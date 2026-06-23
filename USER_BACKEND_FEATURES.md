# 後端管理整合說明

本版本以朋友的 `rollback` 程式為基礎，保留原本巡檢、維修、資產與零件頁面，只做必要整合：

- 使用原本唯一的 `login.php`。
- 登入後依 `Employees.role` 決定能看到的「後端管理」項目。
- 無權限者直接輸入網址會收到 `403 權限不足`。
- 網站入口維持 `http://localhost:8080/`。
- 新增功能全部位於 `src/public/backend-*.php`，沒有第二套 `/backend/` 登入系統。

## 角色可見功能

| 身分 | 可使用功能 |
|---|---|
| 部門主管 `deptmanager` | 後端總覽、資料匯入、巡檢附件、維修報表、庫存提醒、角色通知、權限稽核 |
| 資產管理員 `assetmanager` | 後端總覽、資料匯入、巡檢附件、維修報表、庫存提醒、角色通知 |
| 巡檢人員 `Inspector` | 後端總覽、巡檢附件、角色通知 |
| 維修人員 `Technician` | 後端總覽、庫存提醒、角色通知 |

## 六項工作內容

1. CSV / XLSX 資產匯入與格式驗證。
2. 巡檢照片與附件查詢、上傳、預覽及刪除。
3. 維修成本統計、篩選、CSV 匯出及列印／另存 PDF。
4. 零件安全庫存提醒與補貨通知。
5. 依角色顯示待辦通知、單筆已讀與全部已讀。
6. 主管操作紀錄與權限稽核。

## 新增檔案

- `src/public/backend-common.php`
- `src/public/backend-menu.php`
- `src/public/backend-layout.php`
- `src/public/backend-management.php`
- `src/public/backend-asset-import.php`
- `src/public/backend-photo-manager.php`
- `src/public/backend-maintenance-report.php`
- `src/public/backend-stock-alert.php`
- `src/public/backend-notifications.php`
- `src/public/backend-audit-log.php`
- `src/public/backend-admin.css`
- `migrations/20260623_user_backend_features.sql`

## 對原檔案的必要追加

原本功能沒有被刪除或改名。只有：

- `login.php`、`index.php`：補上主管與資產管理員的登入首頁判斷，避免無限重新導向。
- 原本有側邊欄的頁面：在原選單最後追加一行 `backend_render_menu()`，依角色顯示可用功能。
- `docker-compose.override.yml` 與 `apache-000-default.conf`：以新增檔案把 Web Root 指向 `src/public`，讓網址維持 `http://localhost:8080/`，不修改原本 `docker-compose.yml`。
