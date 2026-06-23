<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/audit.php';

require_roles(['主管', '資產管理員', '巡檢員']);

$pdo = db();
$user = current_user();

if (!$user) {
    header('Location: /login.php');
    exit;
}

$values = [
    'asset_id' => trim((string)($_POST['asset_id'] ?? $_GET['asset_id'] ?? '')),
    'inspector_id' => trim((string)($_POST['inspector_id'] ?? '')),
    'inspec_time' => trim((string)($_POST['inspec_time'] ?? date('Y-m-d\TH:i'))),
    'observation' => trim((string)($_POST['observation'] ?? '')),
    'risk_score' => trim((string)($_POST['risk_score'] ?? '')),
];

if ($user['role'] === '巡檢員') {
    $values['inspector_id'] = (string)$user['id_num'];
}

$errors = [];

$assets = $pdo->query(
    "SELECT
        p.asset_id,
        p.type,
        s.feeder_area,
        s.street,
        h.health_level,
        h.current_status
     FROM PowerAsset p
     LEFT JOIN Sector s
        ON s.sector_id = p.sector_id
     LEFT JOIN Health h
        ON h.asset_id = p.asset_id
       AND h.valid_to IS NULL
     ORDER BY p.asset_id"
)->fetchAll();

$inspectors = $pdo->query(
    "SELECT id_num, name
     FROM Employees
     WHERE role = '巡檢員'
       AND active = 1
     ORDER BY name, id_num"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($values['asset_id'] === '') {
        $errors[] = '請選擇巡檢資產。';
    } else {
        $stmt = $pdo->prepare(
            'SELECT asset_id FROM PowerAsset WHERE asset_id = :asset_id'
        );
        $stmt->execute(['asset_id' => $values['asset_id']]);

        if (!$stmt->fetch()) {
            $errors[] = '找不到指定的資產。';
        }
    }

    if ($values['inspector_id'] === '') {
        $errors[] = '請選擇巡檢員。';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id_num
             FROM Employees
             WHERE id_num = :id_num
               AND role = '巡檢員'
               AND active = 1"
        );
        $stmt->execute(['id_num' => $values['inspector_id']]);

        if (!$stmt->fetch()) {
            $errors[] = '巡檢員不存在、已停用或角色不正確。';
        }
    }

    if ($values['observation'] === '') {
        $errors[] = '請輸入巡檢敘述。';
    } elseif (strlen($values['observation']) > 6000) {
        $errors[] = '巡檢敘述過長。';
    }

    $riskScore = filter_var(
        $values['risk_score'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => 100]]
    );

    if ($riskScore === false) {
        $errors[] = '風險分數必須是 0 到 100 的整數。';
    }

    $inspectionTime = DateTimeImmutable::createFromFormat(
        'Y-m-d\TH:i',
        $values['inspec_time']
    );

    if (
        !$inspectionTime ||
        $inspectionTime->format('Y-m-d\TH:i') !== $values['inspec_time']
    ) {
        $errors[] = '巡檢日期時間格式不正確。';
    }

    if (!$errors && $inspectionTime instanceof DateTimeImmutable) {
        $logId = sprintf(
            'INS-%s-%s',
            $inspectionTime->format('Ymd-His'),
            strtoupper(bin2hex(random_bytes(2)))
        );

        $healthLevel = match (true) {
            $riskScore <= 20 => '優',
            $riskScore <= 50 => '良',
            $riskScore <= 75 => '待修',
            default => '危險',
        };

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO InspectionLog
                    (log_id, asset_id, inspector_id, observation, risk_score, inspec_time)
                 VALUES
                    (:log_id, :asset_id, :inspector_id, :observation, :risk_score, :inspec_time)'
            );

            $stmt->execute([
                'log_id' => $logId,
                'asset_id' => $values['asset_id'],
                'inspector_id' => $values['inspector_id'],
                'observation' => $values['observation'],
                'risk_score' => $riskScore,
                'inspec_time' => $inspectionTime->format('Y-m-d H:i:s'),
            ]);

            $healthStmt = $pdo->prepare(
                'UPDATE Health
                 SET last_inspection_date = :last_inspection_date,
                     health_level = :health_level
                 WHERE asset_id = :asset_id
                   AND valid_to IS NULL'
            );

            $healthStmt->execute([
                'last_inspection_date' => $inspectionTime->format('Y-m-d'),
                'health_level' => $healthLevel,
                'asset_id' => $values['asset_id'],
            ]);

            if ($healthStmt->rowCount() === 0) {
                $insertHealth = $pdo->prepare(
                    "INSERT INTO Health
                        (asset_id, last_inspection_date, health_level, current_status, valid_from)
                     VALUES
                        (:asset_id, :last_inspection_date, :health_level, '運作中', NOW())"
                );

                $insertHealth->execute([
                    'asset_id' => $values['asset_id'],
                    'last_inspection_date' => $inspectionTime->format('Y-m-d'),
                    'health_level' => $healthLevel,
                ]);
            }

            if ($riskScore >= 76) {
                $notificationStmt = $pdo->prepare(
                    'INSERT INTO Notification
                        (recipient_id, role_target, category, title, message, link_url)
                     VALUES
                        (NULL, :role_target, :category, :title, :message, :link_url)'
                );

                foreach (['主管', '資產管理員'] as $roleTarget) {
                    $notificationStmt->execute([
                        'role_target' => $roleTarget,
                        'category' => '巡檢異常',
                        'title' => '高風險巡檢：' . $values['asset_id'],
                        'message' => sprintf(
                            '巡檢紀錄 %s 的風險分數為 %d，請確認是否安排維修。',
                            $logId,
                            $riskScore
                        ),
                        'link_url' => '/photos.php?asset_id=' . urlencode($values['asset_id']),
                    ]);
                }
            }

            $pdo->commit();

            write_audit(
                '新增巡檢紀錄',
                'InspectionLog',
                $logId,
                null,
                [
                    'asset_id' => $values['asset_id'],
                    'inspector_id' => $values['inspector_id'],
                    'observation' => $values['observation'],
                    'risk_score' => $riskScore,
                    'inspec_time' => $inspectionTime->format('Y-m-d H:i:s'),
                    'health_level' => $healthLevel,
                ]
            );

            flash(
                'success',
                '巡檢紀錄已新增，紀錄編號：' . $logId . '。現在可以上傳照片。'
            );

            header(
                'Location: /photos.php?asset_id=' .
                urlencode($values['asset_id'])
            );
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = '新增巡檢紀錄失敗：' . $e->getMessage();
        }
    }
}

$recentSql =
    "SELECT
        i.log_id,
        i.asset_id,
        i.risk_score,
        i.inspec_time,
        i.observation,
        p.type,
        e.name AS inspector_name
     FROM InspectionLog i
     INNER JOIN PowerAsset p
        ON p.asset_id = i.asset_id
     INNER JOIN Employees e
        ON e.id_num = i.inspector_id";

$recentParams = [];

if ($user['role'] === '巡檢員') {
    $recentSql .= ' WHERE i.inspector_id = :inspector_id';
    $recentParams['inspector_id'] = $user['id_num'];
}

$recentSql .= ' ORDER BY i.inspec_time DESC, i.created_at DESC LIMIT 20';

$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute($recentParams);
$recentLogs = $recentStmt->fetchAll();

render_header('新增巡檢紀錄');
?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h2>巡檢紀錄填報</h2>
            <a class="btn btn-outline btn-sm" href="/photos.php">巡檢照片管理</a>
        </div>

        <form method="post">
            <?= csrf_field() ?>

            <div class="field">
                <label for="asset_id">巡檢資產</label>
                <select id="asset_id" name="asset_id" required>
                    <option value="">請選擇資產</option>
                    <?php foreach ($assets as $asset): ?>
                        <option
                            value="<?= e($asset['asset_id']) ?>"
                            <?= selected($values['asset_id'], $asset['asset_id']) ?>
                        >
                            <?= e($asset['asset_id']) ?>
                            ／<?= e($asset['type'] ?? '未分類') ?>
                            <?php if (!empty($asset['feeder_area'])): ?>
                                ／<?= e($asset['feeder_area']) ?>
                            <?php endif; ?>
                            <?php if (!empty($asset['street'])): ?>
                                ／<?= e($asset['street']) ?>
                            <?php endif; ?>
                            <?php if (!empty($asset['health_level'])): ?>
                                ／目前：<?= e($asset['health_level']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row" style="margin-top:14px;">
                <div class="field">
                    <label for="inspec_time">巡檢日期時間</label>
                    <input
                        id="inspec_time"
                        type="datetime-local"
                        name="inspec_time"
                        step="60"
                        value="<?= e($values['inspec_time']) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="risk_score">風險分數（0～100）</label>
                    <input
                        id="risk_score"
                        type="number"
                        name="risk_score"
                        min="0"
                        max="100"
                        step="1"
                        value="<?= e($values['risk_score']) ?>"
                        placeholder="例如：82"
                        required
                    >
                </div>
            </div>

            <div class="field" style="margin-top:14px;">
                <label>巡檢員</label>

                <?php if ($user['role'] === '巡檢員'): ?>
                    <input
                        type="hidden"
                        name="inspector_id"
                        value="<?= e($user['id_num']) ?>"
                    >
                    <input
                        value="<?= e($user['name']) ?>（<?= e($user['id_num']) ?>）"
                        readonly
                    >
                <?php else: ?>
                    <select name="inspector_id" required>
                        <option value="">請選擇巡檢員</option>
                        <?php foreach ($inspectors as $inspector): ?>
                            <option
                                value="<?= e($inspector['id_num']) ?>"
                                <?= selected($values['inspector_id'], $inspector['id_num']) ?>
                            >
                                <?= e($inspector['name']) ?>
                                （<?= e($inspector['id_num']) ?>）
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="field" style="margin-top:14px;">
                <label for="observation">巡檢敘述</label>
                <textarea
                    id="observation"
                    name="observation"
                    rows="7"
                    placeholder="例如：電箱底座明顯鏽蝕，門板有水痕，固定支架尚未鬆動。"
                    required
                ><?= e($values['observation']) ?></textarea>
            </div>

            <div class="toolbar" style="margin-top:16px;">
                <button class="btn btn-success" type="submit">儲存巡檢紀錄</button>
                <a class="btn" href="/inspection_create.php">清除</a>
            </div>

            <p class="small muted" style="margin-top:12px;">
                分數 0～20：優、21～50：良、51～75：待修、76～100：危險。
                高風險紀錄會通知主管與資產管理員。
            </p>
        </form>
    </div>

    <div class="card">
        <h2>填報流程</h2>

        <div style="line-height:1.9;">
            <p><strong>1.</strong> 選擇需要巡檢的資產。</p>
            <p><strong>2.</strong> 填寫巡檢日期、風險分數與現場敘述。</p>
            <p><strong>3.</strong> 儲存後，系統會更新資產健康狀態。</p>
            <p><strong>4.</strong> 系統會跳到巡檢照片頁，再選擇剛新增的紀錄上傳照片。</p>
        </div>
    </div>
</div>

<div class="card" style="margin-top:18px;">
    <div class="card-header">
        <h2>最近巡檢紀錄</h2>
        <span class="muted">最多顯示 20 筆</span>
    </div>

    <?php if (!$recentLogs): ?>
        <div class="empty">目前沒有巡檢紀錄。</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>紀錄編號</th>
                        <th>資產</th>
                        <th>巡檢員</th>
                        <th>時間</th>
                        <th>風險</th>
                        <th>巡檢敘述</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td><?= e($log['log_id']) ?></td>
                            <td>
                                <?= e($log['asset_id']) ?>
                                <div class="small muted"><?= e($log['type'] ?? '-') ?></div>
                            </td>
                            <td><?= e($log['inspector_name']) ?></td>
                            <td><?= e($log['inspec_time']) ?></td>
                            <td>
                                <?php
                                $score = (int)$log['risk_score'];
                                $badgeClass = match (true) {
                                    $score >= 76 => 'badge-danger',
                                    $score >= 51 => 'badge-warning',
                                    $score >= 21 => 'badge-primary',
                                    default => 'badge-success',
                                };
                                ?>
                                <span class="badge <?= e($badgeClass) ?>">
                                    <?= $score ?> 分
                                </span>
                            </td>
                            <td><?= e($log['observation'] ?? '-') ?></td>
                            <td>
                                <a
                                    class="btn btn-sm btn-outline"
                                    href="/photos.php?asset_id=<?= urlencode($log['asset_id']) ?>"
                                >
                                    上傳照片
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
