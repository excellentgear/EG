# RBAC 角色權限機制說明（給 AI / 開發者：在新分頁建立角色權限用）

本系統採用「**角色（Role）→ 功能（Feature）→ 使用者（User）**」的 RBAC 權限模型，
**全系統共用同一套資料表與 API**，但**角色依「模組（module）」分頁分開**，
各頁面只管理自己模組的角色，互不影響。

> 已導入的模組：`quotation`（報價單）、`notice`（公告/通知）。
> 新頁面照本文步驟即可掛上同一套機制。

---

## 一、資料表（共三張 + 一個共用判定）

### 1. `roles`（角色）
| 欄位 | 類型 | 說明 |
|------|------|------|
| `role_id` | INT AUTO_INCREMENT PK | 角色 ID |
| `role_code` | VARCHAR(30) UNIQUE | 角色代碼（系統產生，如 `role_1781051667_522`；系統角色為 `admin`） |
| `role_name` | VARCHAR(50) | 角色顯示名稱（如「業務主管」） |
| `module` | VARCHAR(30) NULL | **所屬模組**（`quotation`/`notice`/…）。`NULL` 代表全頁共用（目前只有系統管理員角色） |
| `is_system` | TINYINT DEFAULT 0 | 1=系統角色（`admin`），不可刪除/改功能 |
| `note` | VARCHAR(200) NULL | 備註 |
| `created_at` | TIMESTAMP | 建立時間 |

### 2. `role_features`（角色擁有的功能）
| 欄位 | 類型 | 說明 |
|------|------|------|
| `role_id` | INT | 對應 `roles.role_id` |
| `feature_code` | VARCHAR(60) | 功能代碼（如 `notice_create`）。特殊值 `all` = 全部功能（管理員） |
| PK | (`role_id`,`feature_code`) | |

### 3. `user_roles`（使用者被指派的角色）
| 欄位 | 類型 | 說明 |
|------|------|------|
| `user_id` | INT | 對應 `user.id` |
| `role_id` | INT | 對應 `roles.role_id` |
| PK | (`user_id`,`role_id`) | 一個使用者可有多個角色 |

### 建表 SQL（`Roles_API.php` 會自動 CREATE IF NOT EXISTS）
```sql
CREATE TABLE IF NOT EXISTS roles (
    role_id    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_code  VARCHAR(30) NOT NULL UNIQUE,
    role_name  VARCHAR(50) NOT NULL,
    module     VARCHAR(30) NULL,            -- 後加：分頁模組
    is_system  TINYINT NOT NULL DEFAULT 0,
    note       VARCHAR(200),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_features (
    role_id      INT NOT NULL,
    feature_code VARCHAR(60) NOT NULL,
    PRIMARY KEY (role_id, feature_code),
    INDEX idx_rf_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (user_id, role_id),
    INDEX idx_ur_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 系統管理員角色（全頁共用，module 為 NULL）
INSERT IGNORE INTO roles (role_code, role_name, is_system) VALUES ('admin', '管理員', 1);
-- 給 admin 角色 'all' 功能
INSERT IGNORE INTO role_features (role_id, feature_code)
SELECT role_id, 'all' FROM roles WHERE role_code = 'admin';
```

> 若 `roles` 已存在但沒有 `module` 欄，加欄並標記既有角色：
> ```sql
> ALTER TABLE roles ADD COLUMN module VARCHAR(30) NULL AFTER role_name;
> UPDATE roles SET module='quotation' WHERE (module IS NULL OR module='') AND is_system=0; -- 視既有角色原屬頁面而定
> UPDATE roles SET module=NULL WHERE is_system=1; -- 系統角色保持全頁共用
> ```

---

## 二、核心約定（務必遵守）

1. **功能代碼命名**：`<模組>_<動作>`，例：`notice_view`/`notice_create`/`notice_edit`/`notice_delete`、
   `quotation_view`/`quotation_create`/…。功能代碼**全系統唯一**，不同模組不可撞名。
2. **`all`**：管理員專用，`rbac_has()` 視為擁有任何功能。
3. **`module`**：每個頁面用固定字串（如 `notice`）。設定/指派角色時都要帶上，才能分頁分開。
4. **系統角色 `admin`（`is_system=1`,`module=NULL`）**：全頁共用、永遠出現在每個模組的角色清單、不可刪除/改功能。
5. **權限判定的核心規則（重要，常見錯誤點）**：
   - **已被指派角色的使用者 → 一律以其角色功能聯集為準**，即使該角色沒有勾選任何功能（＝無權限）。
     **不可**因為「系統還沒有管理員」就把已指派角色的人放寬成全權，否則會出現
     「指派了唯讀角色，卻仍擁有管理員全部功能」的 bug。
   - **Bootstrap 安全裝置只對「完全未被指派任何角色」的人生效**：
     若此人**沒有任何角色**，且系統目前也**還沒有任何人擁有 `all`（管理員）**，
     才暫時給予全權，讓最初的管理者能進入頁面去建立/指派角色。
   - 一旦系統有人被指派管理員(`all`)：未指派角色者＝無權限（被擋）；已指派者＝照其角色。

---

## 三、共用元件

### 1. 權限判定 `src/common/rbac.php`
```php
require_once '.../src/common/rbac.php';
$features = rbac_user_features($pdo, $userId); // 回傳該使用者所有 feature_code
rbac_has($features, 'notice_create');          // bool：是否擁有該功能（'all' 視為全有）
```
`rbac_user_features()` 的**正確判定流程**（務必照此，勿無條件套用 bootstrap）：
```php
// 1) 此人是否已被指派任何角色？
$hasRoles = (bool) 查詢 SELECT 1 FROM user_roles WHERE user_id = :uid LIMIT 1;

if ($hasRoles) {
    // 2a) 已指派 → 直接回傳其角色功能聯集（嚴格，不再 bootstrap 覆蓋）
    return 查詢 "SELECT DISTINCT rf.feature_code
                 FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id
                 WHERE ur.user_id = :uid";
}

// 2b) 完全未指派角色 → 才看 bootstrap
$anyAdmin = (int) 查詢 "SELECT COUNT(*) FROM user_roles ur
                        JOIN role_features rf ON rf.role_id = ur.role_id
                        WHERE rf.feature_code = 'all'";
return ($anyAdmin === 0) ? ['all'] : [];  // 系統尚無管理員→暫時全權；否則→無權限
```
> `Roles_API.php` 的 `isAdmin()` 也採**相同**判定（已指派者看是否含 `all`；未指派者才套 bootstrap），兩邊務必一致。

> 頁面若要顯示「目前使用者角色／是否初始管理者」，判斷
> `$effective_bootstrap_admin = (系統無管理員) && (此人未被指派任何角色)`，
> 不可只用「系統無管理員」，否則已指派角色的人會被誤標為管理員。

### 2. 角色管理 API `src/store/Roles_API.php`（POST/GET `action=`）
| action | 參數 | 說明 |
|--------|------|------|
| `get_roles` | `module`(選填) | 取角色清單；帶 module → `WHERE module=? OR is_system=1`（含系統 admin） |
| `save_role` | `role_name`,`module`(新增時),`role_id`(改名時) | 新增或改名（改名不動 module） |
| `delete_role` | `role_id` | 刪除（系統角色不可刪；連同 role_features/user_roles 一併刪） |
| `get_role_features` | `role_id` | 取該角色的 feature_code 陣列 |
| `save_role_features` | `role_id`,`features`(JSON 陣列) | 覆寫該角色功能（系統角色不可改） |
| `get_users` | `module`(選填) | 取所有使用者及其角色；帶 module → 每人只回該模組+系統角色 |
| `assign_user_role` | `user_id`,`role_id` | 指派 |
| `remove_user_role` | `user_id`,`role_id` | 移除 |
| `get_user_features` | `user_id`(選填) | 取某使用者所有 feature_code |

> 寫入類動作（save/delete/assign/remove）會檢查呼叫者是否為管理員（`isAdmin()`，同樣有 bootstrap）。

`get_roles` 的 module 過濾 SQL：
```sql
SELECT role_id, role_code, role_name, is_system, note, module
FROM roles
WHERE module = :module OR is_system = 1
ORDER BY is_system DESC, role_id ASC;
```

---

## 四、在「新分頁」掛上角色權限：步驟範本

以下以新模組 `xxx` 為例（請整批把 `xxx` 換成你的模組名）。

### 步驟 1：頁面 PHP 頂端 — 算權限 + 擋頁
```php
require_once '../../src/common/rbac.php';
$_features  = rbac_user_features($conn->getPDO(), (int)$id); // $id = $_SESSION['id']
$CAN_VIEW   = rbac_has($_features, 'xxx_view');
$CAN_CREATE = rbac_has($_features, 'xxx_create');
$CAN_EDIT   = rbac_has($_features, 'xxx_edit');
$CAN_DELETE = rbac_has($_features, 'xxx_delete');
$IS_ADMIN   = rbac_has($_features, 'all');

// 無檢視權限 → 導回儀表板（在任何 HTML 輸出前）
// 【重要】此時使用者「已登入」，嚴禁設定 $_SESSION['lastpage'] 再導回登入頁(index.php)！
// 否則登入成功後會被 Login.php 導回本頁 → 本頁又踢回登入頁 → 無限循環，帳密正確也永遠登不進去。
// lastpage 只能用於「未登入」的情況（登入後返回原頁）。
if (!$CAN_VIEW) {
    header("Location:../../views/admin/dashboard.php"); exit();
}

// 本頁功能清單（供「權限設定」勾選）
$PAGE_FEATURES = [
    ['code' => 'xxx_view',   'label' => '檢視'],
    ['code' => 'xxx_create', 'label' => '新增'],
    ['code' => 'xxx_edit',   'label' => '編輯'],
    ['code' => 'xxx_delete', 'label' => '刪除'],
];
```

### 步驟 2：UI 依權限顯示
```php
<?php if ($CAN_CREATE) : ?> ...新增表單... <?php endif; ?>
<?php if ($CAN_EDIT)   : ?> ...編輯按鈕... <?php endif; ?>
<?php if ($CAN_DELETE) : ?> ...刪除按鈕... <?php endif; ?>
```

### 步驟 3：伺服器端把關（後端 store/處理檔，避免略過前端直接 POST）
```php
require_once __DIR__ . '/../common/rbac.php';
$f = rbac_user_features($db /* PDO */, (int)($_SESSION['id'] ?? 0));
if (!rbac_has($f, 'xxx_create')) { /* 拒絕：return/exit/轉頁 */ }
```

### 步驟 4：管理員（`$IS_ADMIN`）才出現「權限設定」modal
- HTML：左邊角色清單（新增/改名/刪除），右邊把 `$PAGE_FEATURES` 印成 checkbox。
- JS：所有 Roles_API 呼叫都要帶 `module: 'xxx'`：
```js
var XXX_MODULE = 'xxx';
$.get(ROLES_API, { action:'get_roles', module: XXX_MODULE }, ...);                 // 取角色
$.post(ROLES_API,{ action:'save_role', role_name:name, module: XXX_MODULE }, ...);  // 新增角色
$.post(ROLES_API,{ action:'save_role', role_id:rid, role_name:name }, ...);         // 改名（不帶 module）
$.post(ROLES_API,{ action:'delete_role', role_id:rid }, ...);                       // 刪除
$.get(ROLES_API, { action:'get_role_features', role_id:rid }, ...);                 // 載入該角色功能
$.post(ROLES_API,{ action:'save_role_features', role_id:rid, features: JSON.stringify(codes) }, ...); // 存功能
```
> 可直接複製 `views/liveEvent/createEvent.php` 內 `#permModal` + `permLoadRoles/permSelectRole/permSaveFeatures/permAddRole/permRenameRole/permDeleteRole` 整段，改 `NOTICE_MODULE` 為你的模組即可。
> 刪除角色前會以 `get_users` 列出被指定者，提供「轉換為其他角色」並需手動輸入大寫 `Y` 才執行。

### 步驟 5：使用者指派角色 — 在 `views/user/user_permissions.php` 加一個區塊
已抽成共用函式，直接加一行：
```php
eg_render_role_section('xxx', 'xxx', '你的頁面名稱', 'fa-圖示', '#色碼',
    '提示文字…', $_xxxRoles, $_userXxxRoles, $admins, $_quotDepts, $canEdit);
```
並在該檔上方載入資料（依模組過濾）：
```sql
-- 角色清單（含系統 admin）
SELECT role_id, role_name, is_system FROM roles
WHERE module='xxx' OR is_system=1 ORDER BY is_system DESC, role_id ASC;

-- 使用者已指派角色（依模組）
SELECT ur.user_id, r.role_id, r.role_name
FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
WHERE r.module='xxx' OR r.is_system=1;
```
JS 已通用化（`roleFilterTable/roleClearSearch/roleAssign/roleRemove/roleReloadRow`，吃 `prefix`+`module`），新區塊免寫 JS。

---

## 五、常用查詢速查

```sql
-- 某使用者擁有的所有功能
SELECT DISTINCT rf.feature_code
FROM user_roles ur JOIN role_features rf ON rf.role_id=ur.role_id
WHERE ur.user_id = :uid;

-- 某模組的角色（含系統 admin）
SELECT * FROM roles WHERE module = :module OR is_system = 1 ORDER BY is_system DESC, role_id;

-- 某角色目前被指定給哪些人（刪除前確認用）
SELECT u.id, u.user_cname
FROM user_roles ur JOIN user u ON u.id = ur.user_id
WHERE ur.role_id = :role_id;

-- 轉換角色（把 A 角色的人改成 B 角色，再刪 A）
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT user_id, :newRoleId FROM user_roles WHERE role_id = :oldRoleId;
DELETE FROM user_roles   WHERE role_id = :oldRoleId;
DELETE FROM role_features WHERE role_id = :oldRoleId;
DELETE FROM roles         WHERE role_id = :oldRoleId AND is_system = 0;

-- 是否已有人是管理員（判斷 bootstrap）
SELECT COUNT(*) FROM user_roles ur JOIN role_features rf ON rf.role_id=ur.role_id WHERE rf.feature_code='all';
```

---

## 六、已實作參考檔案
- 共用判定：`src/common/rbac.php`
- 共用 API：`src/store/Roles_API.php`
- 範例頁面（含權限設定 modal）：`views/liveEvent/createEvent.php`（module=`notice`）、`views/Sales/quotation_list_NEW.php`（module=`quotation`）
- 角色指派頁：`views/user/user_permissions.php`（共用函式 `eg_render_role_section`）
