<?php
include_once '../../src/common/_config.php';
include "../../src/common/DBConnection.php";

// 檢查登入狀態
if (!isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    echo "<script>alert('連線逾時，請重新登入'); window.location.href='../../index.php';</script>";
    exit;
}

$db = new DBConnection();
$pdo = $db->getPDO();

$user_cname = trim($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? '未知');
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

// =============================================================================
// 後端 API 處理區塊
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        // 儲存檢驗結果
        if ($_POST['action'] === 'save_inspection') {
            $header = $_POST['header'] ?? [];
            $jsonData = $_POST['form_json'] ?? '{}';
            $recordId = $_POST['record_id'] ?? null;

            $pdo->beginTransaction();

            if ($recordId) {
                // --- 更新模式 ---
                $sql_main = "UPDATE qc_packing_inspection SET 
                    inspection_date = ?, customer_name = ?, order_qty = ?, inspected_qty = ?, ok_qty = ?, 
                    ng_qty = ?, judgement = ?, inspector = ?, packer = ?, remark = ?
                    WHERE packing_inspection_id = ?";
                $stmt_main = $pdo->prepare($sql_main);
                $stmt_main->execute([
                    $header['inspection_date'], $header['customer_name'], $header['order_qty'], $header['inspected_qty'],
                    $header['ok_qty'], $header['ng_qty'], $header['judgement'], $header['inspector'], $header['packer'],
                    $header['main_remark'], $recordId
                ]);

                $sql_data = "UPDATE qc_packing_inspection_data SET data_json = ? WHERE packing_inspection_id = ?";
                $stmt_data = $pdo->prepare($sql_data);
                $stmt_data->execute([$jsonData, $recordId]);
                $packingId = $recordId;
            } else {
                // --- 新增模式 ---
                $sql_main = "INSERT INTO qc_packing_inspection 
                    (inspection_date, customer_name, order_qty, inspected_qty, ok_qty, ng_qty, judgement, inspector, packer, remark) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_main = $pdo->prepare($sql_main);
                $stmt_main->execute([
                    $header['inspection_date'], $header['customer_name'], $header['order_qty'], $header['inspected_qty'],
                    $header['ok_qty'], $header['ng_qty'], $header['judgement'], $header['inspector'], $header['packer'],
                    $header['main_remark']
                ]);
                $packingId = $pdo->lastInsertId();

                $sql_data = "INSERT INTO qc_packing_inspection_data (packing_inspection_id, data_json) VALUES (?, ?)";
                $stmt_data = $pdo->prepare($sql_data);
                $stmt_data->execute([$packingId, $jsonData]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => '儲存成功', 'id' => $packingId]);
        }
        // 獲取歷史紀錄列表
        elseif ($_POST['action'] === 'get_history') {
            $stmt = $pdo->query("SELECT packing_inspection_id, inspection_date, customer_name, order_qty, ok_qty, ng_qty, judgement, inspector, packer FROM qc_packing_inspection ORDER BY inspection_date DESC, packing_inspection_id DESC LIMIT 100");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        // 獲取單筆紀錄詳情
        elseif ($_POST['action'] === 'get_record') {
            $id = $_POST['id'];
            $stmt_main = $pdo->prepare("SELECT * FROM qc_packing_inspection WHERE packing_inspection_id = ?");
            $stmt_main->execute([$id]);
            $header = $stmt_main->fetch(PDO::FETCH_ASSOC);

            $stmt_data = $pdo->prepare("SELECT data_json FROM qc_packing_inspection_data WHERE packing_inspection_id = ?");
            $stmt_data->execute([$id]);
            $jsonData = $stmt_data->fetchColumn();

            if ($header) {
                echo json_encode(['success' => true, 'header' => $header, 'form_json' => json_decode($jsonData, true)]);
            } else {
                throw new Exception("找不到紀錄");
            }
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>包裝出貨檢驗表</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        .section-title {
            background-color: #f5f5f5;
            padding: 8px;
            font-weight: bold;
            border-top: 2px solid #ddd;
            margin-top: 15px;
        }
        .form-table td { vertical-align: middle !important; }
        .form-table .input-group-addon { min-width: 80px; text-align: left; }
        .sub-item { padding-left: 20px; }
        .other-input { display: inline-block; width: auto; margin-left: 5px; }
        #container-rows .row { margin-bottom: 5px; }
    </style>
</head>
<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <div class="right_col" role="main">
                <div class="">
                    <div class="page-title">
                        <div class="title_left">
                            <h3>包裝出貨檢驗表 <small>Packaging & Shipping Inspection</small></h3>
                        </div>
                        <div class="title_right">
                            <button class="btn btn-default pull-right" id="btn-history"><i class="fa fa-history"></i> 歷史紀錄</button>
                            <button class="btn btn-primary pull-right" id="btn-new-form"><i class="fa fa-plus"></i> 開新表單</button>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_content">
                                    <form id="packing-inspection-form">
                                        <input type="hidden" id="record-id">

                                        <!-- ==================== 主資訊 ==================== -->
                                        <div class="row">
                                            <div class="col-md-3 form-group">
                                                <label>檢驗日期</label>
                                                <input type="date" id="inspection_date" class="form-control" value="<?= date('Y-m-d') ?>">
                                            </div>
                                            <div class="col-md-3 form-group">
                                                <label>客戶名稱</label>
                                                <input type="text" id="customer_name" class="form-control">
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label>關聯BOM (可多筆, 用逗號分隔)</label>
                                                <input type="text" id="related_boms" class="form-control" placeholder="例如: B-123, B-456">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-3 form-group">
                                                <label>訂單數量</label>
                                                <input type="number" id="order_qty" class="form-control">
                                            </div>
                                            <div class="col-md-3 form-group">
                                                <label>實際全檢數量</label>
                                                <input type="number" id="inspected_qty" class="form-control">
                                            </div>
                                            <div class="col-md-3 form-group">
                                                <label>合格數量</label>
                                                <input type="number" id="ok_qty" class="form-control">
                                            </div>
                                            <div class="col-md-3 form-group">
                                                <label>NG總數</label>
                                                <input type="number" id="ng_qty" class="form-control" readonly style="background:#eee;">
                                            </div>
                                        </div>

                                        <!-- ==================== 外觀檢驗 ==================== -->
                                        <h4 class="section-title">外觀檢驗</h4>
                                        <table class="table table-bordered form-table">
                                            <thead>
                                                <tr>
                                                    <th>項目</th>
                                                    <th>檢驗方式</th>
                                                    <th>檢驗工具</th>
                                                    <th>異常數量</th>
                                                    <th>處置狀況</th>
                                                </tr>
                                            </thead>
                                            <tbody id="appearance-section">
                                                <!-- JS 動態生成 -->
                                            </tbody>
                                        </table>

                                        <!-- ==================== 備註 ==================== -->
                                        <h4 class="section-title">備註</h4>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h5>加強防銹</h5>
                                                <div id="rust-proofing-section"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <h5>確認防撞</h5>
                                                <div id="collision-proofing-section"></div>
                                            </div>
                                        </div>
                                        <div class="row" style="margin-top:10px;">
                                            <div class="col-md-6">
                                                <h5>治具/模具/量具 歸還</h5>
                                                <div class="input-group">
                                                    <input type="number" id="return_jigs" class="form-control" placeholder="數量">
                                                    <span class="input-group-addon">個</span>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <h5>樣品 歸還</h5>
                                                <div class="input-group">
                                                    <input type="number" id="return_samples" class="form-control" placeholder="數量">
                                                    <span class="input-group-addon">個</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- ==================== 容器 ==================== -->
                                        <h4 class="section-title">容器</h4>
                                        <div id="container-rows"></div>
                                        <button type="button" class="btn btn-default btn-sm" id="btn-add-container"><i class="fa fa-plus"></i> 新增容器</button>

                                        <!-- ==================== 包裝說明 ==================== -->
                                        <h4 class="section-title">包裝說明</h4>
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label>實際出貨數量說明</label>
                                                <input type="text" id="shipping_desc" class="form-control" placeholder="例如: 100pcs/箱 * 5箱 + 20pcs = 520pcs">
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label>成品入庫方式</label>
                                                <div id="storage-method-section"></div>
                                            </div>
                                        </div>

                                        <!-- ==================== 判定與儲存 ==================== -->
                                        <div class="ln_solid"></div>
                                        <div class="row">
                                            <div class="col-md-3 form-group">
                                                <label>品檢人員</label>
                                                <input type="text" id="inspector" class="form-control" value="<?= htmlspecialchars($user_cname) ?>">
                                            </div>
                                            <div class="col-md-3 form-group">
                                                <label>包裝人員</label>
                                                <input type="text" id="packer" class="form-control">
                                            </div>
                                            <div class="col-md-3 form-group">
                                                <label>判定結果</label>
                                                <select id="judgement" class="form-control">
                                                    <option value="PENDING">PENDING (處理中)</option>
                                                    <option value="PASS">PASS (合格)</option>
                                                    <option value="FAIL">FAIL (不合格)</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label>整張表備註</label>
                                            <textarea id="main_remark" class="form-control" rows="3"></textarea>
                                        </div>
                                        <div class="text-center">
                                            <button type="button" class="btn btn-success btn-lg" id="btn-save"><i class="fa fa-save"></i> 儲存紀錄</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php include '../partPage/footer.html' ?>
        </div>
    </div>

    <!-- History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">歷史紀錄</h4>
                </div>
                <div class="modal-body">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>檢驗日期</th>
                                <th>客戶</th>
                                <th>訂單數</th>
                                <th>合格數</th>
                                <th>NG數</th>
                                <th>判定</th>
                                <th>人員</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="history-list"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>

    <script>
    $(document).ready(function() {

        // ========================================================================
        // CONFIGURATION
        // ========================================================================
        const appearanceItems = [
            { id: 'rust', label: '生鏽', dispositions: ['無生鏽', '生鏽已除銹', '其他'] },
            { id: 'damage', label: '碰撞傷', dispositions: ['無碰撞傷', '碰撞傷已修整', '其他'] },
            { id: 'oxide_scale', label: '黑皮', dispositions: ['無黑皮', '黑皮已修整', '其他'] },
            { id: 'burrs', label: '毛邊', dispositions: ['無毛邊', '毛邊已修整', '其他'] },
            { id: 'laser_etch', label: '雷刻', dispositions: ['不須雷刻', '雷刻正確', '其他'] }
        ];

        const remarkOptions = {
            'rust-proofing': { label: '加強防銹', options: ['防銹袋', '防銹油', '其他'] },
            'collision-proofing': { label: '確認防撞', options: ['泡殼', '隔板', '氣泡紙', '報紙', '其他'] }
        };

        const containerOptions = ['紙箱', '塑膠桶', '蝴蝶籠', '鐵桶', '鐵架', '其他'];
        const containerSources = ['客供', '超正', '無印刷'];

        const storageMethods = [
            { value: 'direct', label: '直接入庫' },
            { value: 'pallet', label: '棧板+膠膜' }
        ];

        // ========================================================================
        // INITIALIZATION
        // ========================================================================
        function initializeForm() {
            // Render Appearance Section
            let appearanceHtml = '';
            appearanceItems.forEach(item => {
                let dispositionHtml = '<div class="btn-group" data-toggle="buttons">';
                item.dispositions.forEach((disp, index) => {
                    dispositionHtml += `
                        <label class="btn btn-default btn-xs">
                            <input type="checkbox" name="${item.id}_disp" value="${disp}"> ${disp}
                        </label>
                    `;
                });
                dispositionHtml += '</div>';
                dispositionHtml += `<input type="text" class="form-control input-sm other-input" name="${item.id}_other" placeholder="其他說明" style="display:none;">`;

                appearanceHtml += `
                    <tr data-id="${item.id}">
                        <td>${item.label}</td>
                        <td>全檢</td>
                        <td>目視</td>
                        <td><input type="number" name="${item.id}_ng_qty" class="form-control input-sm ng-qty-input" style="width:80px;"></td>
                        <td>${dispositionHtml}</td>
                    </tr>
                `;
            });
            $('#appearance-section').html(appearanceHtml);

            // Render Rust Proofing
            let rustHtml = '';
            remarkOptions['rust-proofing'].options.forEach(opt => {
                rustHtml += `<label class="checkbox-inline"><input type="checkbox" name="rust_proofing_opt" value="${opt}"> ${opt}</label>`;
            });
            rustHtml += `<input type="text" class="form-control input-sm other-input" name="rust_proofing_other" placeholder="其他說明" style="display:none;">`;
            $('#rust-proofing-section').html(rustHtml);

            // Render Collision Proofing
            let collisionHtml = '';
            remarkOptions['collision-proofing'].options.forEach(opt => {
                collisionHtml += `<label class="checkbox-inline"><input type="checkbox" name="collision_proofing_opt" value="${opt}"> ${opt}</label>`;
                if (opt === '泡殼') {
                    collisionHtml += ` (<input type="number" class="form-control input-sm other-input" name="blister_pack_1" style="width:60px;"> 入 x <input type="number" class="form-control input-sm other-input" name="blister_pack_2" style="width:60px;"> 個)`;
                }
            });
            collisionHtml += `<input type="text" class="form-control input-sm other-input" name="collision_proofing_other" placeholder="其他說明" style="display:none;">`;
            $('#collision-proofing-section').html(collisionHtml);

            // Render Storage Methods
            let storageHtml = '';
            storageMethods.forEach(method => {
                storageHtml += `<label class="radio-inline"><input type="radio" name="storage_method" value="${method.value}" ${method.value === 'direct' ? 'checked' : ''}> ${method.label}</label>`;
            });
            storageHtml += `<input type="number" id="pallet_qty" class="form-control input-sm other-input" placeholder="數量" style="display:none;">`;
            $('#storage-method-section').html(storageHtml);

            // Add one default container row
            addContainerRow();
        }

        initializeForm();

        // ========================================================================
        // EVENT HANDLERS
        // ========================================================================

        // Show/hide "other" text input when "其他" checkbox is toggled
        $(document).on('change', 'input[type="checkbox"]', function() {
            const $this = $(this);
            const $otherInput = $this.closest('div, td').find('.other-input');
            if ($this.val() === '其他') {
                $otherInput.toggle($this.prop('checked'));
            }
        });

        // Show/hide pallet quantity input
        $(document).on('change', 'input[name="storage_method"]', function() {
            $('#pallet_qty').toggle($(this).val() === 'pallet');
        });

        // Calculate total NG quantity automatically
        $(document).on('input', '.ng-qty-input', function() {
            let totalNg = 0;
            $('.ng-qty-input').each(function() {
                totalNg += parseInt($(this).val()) || 0;
            });
            $('#ng_qty').val(totalNg);
        });

        // Add/Remove Container Rows
        $('#btn-add-container').click(addContainerRow);
        $(document).on('click', '.btn-remove-container', function() {
            $(this).closest('.row').remove();
        });

        function addContainerRow() {
            const typeOptions = containerOptions.map(opt => `<option value="${opt}">${opt}</option>`).join('');
            const sourceRadios = containerSources.map((src, i) => `
                <label class="radio-inline">
                    <input type="radio" name="container_source_${$('.row.container-item').length}" value="${src}" ${i===0 ? 'checked' : ''}> ${src}
                </label>
            `).join('');

            const newRow = `
                <div class="row container-item">
                    <div class="col-md-3"><select class="form-control container-type">${typeOptions}</select></div>
                    <div class="col-md-2"><input type="number" class="form-control container-qty" placeholder="數量"></div>
                    <div class="col-md-6">${sourceRadios}</div>
                    <div class="col-md-1"><button type="button" class="btn btn-danger btn-xs btn-remove-container"><i class="fa fa-trash"></i></button></div>
                </div>
            `;
            $('#container-rows').append(newRow);
        }

        // New/Clear form
        $('#btn-new-form').click(function() {
            if (confirm('確定要清空目前表單並開啟一張新的嗎？')) {
                $('#packing-inspection-form')[0].reset();
                $('#record-id').val('');
                $('#inspection_date').val('<?= date('Y-m-d') ?>');
                $('#inspector').val('<?= htmlspecialchars($user_cname) ?>');
                $('#container-rows').empty();
                addContainerRow();
                // Reset all "other" inputs
                $('.other-input').hide();
                // Reset NG total
                $('#ng_qty').val('');
            }
        });

        // ========================================================================
        // DATA COLLECTION & SAVING
        // ========================================================================

        function collectFormData() {
            const data = {
                boms: $('#related_boms').val().split(',').map(s => s.trim()).filter(Boolean),
                appearance: {},
                remarks: {
                    rust_proofing: { options: [], other_text: '' },
                    collision_proofing: { options: [], blister_pack_1: '', blister_pack_2: '', other_text: '' },
                    returns: { jigs: '', samples: '' }
                },
                containers: [],
                shipping: {
                    description: '',
                    storage_method: '',
                    pallet_qty: ''
                }
            };

            // Appearance
            $('#appearance-section tr').each(function() {
                const $row = $(this);
                const id = $row.data('id');
                const dispositions = [];
                $row.find('input[type="checkbox"]:checked').each(function() {
                    dispositions.push($(this).val());
                });
                data.appearance[id] = {
                    disposition: dispositions,
                    other_text: $row.find('input[name*="_other"]').val(),
                    ng_qty: $row.find('.ng-qty-input').val()
                };
            });

            // Remarks - Rust Proofing
            $('input[name="rust_proofing_opt"]:checked').each(function() {
                data.remarks.rust_proofing.options.push($(this).val());
            });
            data.remarks.rust_proofing.other_text = $('input[name="rust_proofing_other"]').val();

            // Remarks - Collision Proofing
            $('input[name="collision_proofing_opt"]:checked').each(function() {
                data.remarks.collision_proofing.options.push($(this).val());
            });
            data.remarks.collision_proofing.blister_pack_1 = $('input[name="blister_pack_1"]').val();
            data.remarks.collision_proofing.blister_pack_2 = $('input[name="blister_pack_2"]').val();
            data.remarks.collision_proofing.other_text = $('input[name="collision_proofing_other"]').val();

            // Remarks - Returns
            data.remarks.returns.jigs = $('#return_jigs').val();
            data.remarks.returns.samples = $('#return_samples').val();

            // Containers
            $('.container-item').each(function() {
                const $row = $(this);
                data.containers.push({
                    type: $row.find('.container-type').val(),
                    qty: $row.find('.container-qty').val(),
                    source: $row.find('input[type="radio"]:checked').val()
                });
            });

            // Shipping
            data.shipping.description = $('#shipping_desc').val();
            data.shipping.storage_method = $('input[name="storage_method"]:checked').val();
            data.shipping.pallet_qty = $('#pallet_qty').val();

            return data;
        }

        $('#btn-save').click(function() {
            const headerData = {
                inspection_date: $('#inspection_date').val(),
                customer_name: $('#customer_name').val(),
                order_qty: $('#order_qty').val() || null,
                inspected_qty: $('#inspected_qty').val() || null,
                ok_qty: $('#ok_qty').val() || null,
                ng_qty: $('#ng_qty').val() || 0,
                judgement: $('#judgement').val(),
                inspector: $('#inspector').val(),
                packer: $('#packer').val(),
                main_remark: $('#main_remark').val()
            };

            const formData = collectFormData();

            $.post('packaging_inspection_entry.php', {
                action: 'save_inspection',
                record_id: $('#record-id').val(),
                header: headerData,
                form_json: JSON.stringify(formData)
            }, function(res) {
                if (res.success) {
                    alert('儲存成功！');
                    $('#record-id').val(res.id);
                } else {
                    alert('儲存失敗：' + res.message);
                }
            }, 'json').fail(function() {
                alert('伺服器錯誤，請稍後再試。');
            });
        });

        // ========================================================================
        // HISTORY & LOADING
        // ========================================================================
        $('#btn-history').click(function() {
            $('#history-list').html('<tr><td colspan="8" class="text-center">載入中...</td></tr>');
            $('#historyModal').modal('show');
            $.post('packaging_inspection_entry.php', { action: 'get_history' }, function(res) {
                if (res.success) {
                    let html = '';
                    if (res.data.length === 0) {
                        html = '<tr><td colspan="8" class="text-center">無歷史紀錄</td></tr>';
                    } else {
                        res.data.forEach(row => {
                            html += `
                                <tr>
                                    <td>${row.inspection_date}</td>
                                    <td>${row.customer_name || ''}</td>
                                    <td>${row.order_qty || ''}</td>
                                    <td>${row.ok_qty || ''}</td>
                                    <td>${row.ng_qty || '0'}</td>
                                    <td>${row.judgement}</td>
                                    <td>${row.inspector || ''} / ${row.packer || ''}</td>
                                    <td><button class="btn btn-xs btn-info btn-load-record" data-id="${row.packing_inspection_id}">載入</button></td>
                                </tr>
                            `;
                        });
                    }
                    $('#history-list').html(html);
                }
            }, 'json');
        });

        $(document).on('click', '.btn-load-record', function() {
            const id = $(this).data('id');
            if (!confirm('確定要載入此筆紀錄嗎？將會覆蓋目前表單內容。')) return;

            $.post('packaging_inspection_entry.php', { action: 'get_record', id: id }, function(res) {
                if (res.success) {
                    // Clear form first
                    $('#btn-new-form').click();

                    // Populate Header
                    $('#record-id').val(res.header.packing_inspection_id);
                    $('#inspection_date').val(res.header.inspection_date);
                    $('#customer_name').val(res.header.customer_name);
                    $('#order_qty').val(res.header.order_qty);
                    $('#inspected_qty').val(res.header.inspected_qty);
                    $('#ok_qty').val(res.header.ok_qty);
                    $('#ng_qty').val(res.header.ng_qty);
                    $('#judgement').val(res.header.judgement);
                    $('#inspector').val(res.header.inspector);
                    $('#packer').val(res.header.packer);
                    $('#main_remark').val(res.header.remark);

                    // Populate JSON data
                    const data = res.form_json;
                    if (!data) {
                        $('#historyModal').modal('hide');
                        return;
                    }

                    $('#related_boms').val((data.boms || []).join(', '));

                    // Appearance
                    for (const [key, value] of Object.entries(data.appearance || {})) {
                        const $row = $(`#appearance-section tr[data-id="${key}"]`);
                        $row.find('.ng-qty-input').val(value.ng_qty);
                        (value.disposition || []).forEach(disp => {
                            $row.find(`input[value="${disp}"]`).prop('checked', true).closest('label').addClass('active');
                        });
                        if ((value.disposition || []).includes('其他')) {
                            $row.find('input[name*="_other"]').val(value.other_text).show();
                        }
                    }

                    // Remarks
                    const remarks = data.remarks || {};
                    (remarks.rust_proofing?.options || []).forEach(opt => {
                        $(`input[name="rust_proofing_opt"][value="${opt}"]`).prop('checked', true);
                    });
                    if ((remarks.rust_proofing?.options || []).includes('其他')) {
                        $('input[name="rust_proofing_other"]').val(remarks.rust_proofing.other_text).show();
                    }

                    (remarks.collision_proofing?.options || []).forEach(opt => {
                        $(`input[name="collision_proofing_opt"][value="${opt}"]`).prop('checked', true);
                    });
                    $('input[name="blister_pack_1"]').val(remarks.collision_proofing?.blister_pack_1);
                    $('input[name="blister_pack_2"]').val(remarks.collision_proofing?.blister_pack_2);
                    if ((remarks.collision_proofing?.options || []).includes('其他')) {
                        $('input[name="collision_proofing_other"]').val(remarks.collision_proofing.other_text).show();
                    }

                    $('#return_jigs').val(remarks.returns?.jigs);
                    $('#return_samples').val(remarks.returns?.samples);

                    // Containers
                    $('#container-rows').empty();
                    (data.containers || []).forEach(c => {
                        addContainerRow();
                        const $newRow = $('.container-item').last();
                        $newRow.find('.container-type').val(c.type);
                        $newRow.find('.container-qty').val(c.qty);
                        $newRow.find(`input[value="${c.source}"]`).prop('checked', true);
                    });

                    // Shipping
                    const shipping = data.shipping || {};
                    $('#shipping_desc').val(shipping.description);
                    $(`input[name="storage_method"][value="${shipping.storage_method}"]`).prop('checked', true);
                    if (shipping.storage_method === 'pallet') {
                        $('#pallet_qty').val(shipping.pallet_qty).show();
                    }

                    $('#historyModal').modal('hide');
                } else {
                    alert('載入失敗：' + res.message);
                }
            }, 'json');
        });

    });
    </script>
</body>
</html>