<?php
/**
 * マイグレーション: プロファイル設定のユーティリティアクション（Import/Export/Merge/重複の検出）不整合を修正
 * 生成日時: 20260826010251
 */

require_once dirname(__FILE__) . '/../FRMigrationClass.php';

class Migration20260826010251_SyncProfileUtilityActions extends FRMigrationClass {

    public function process() {
        global $adb;

        // 全プロファイルのIDを取得
        $profileResult = $adb->pquery("SELECT profileid FROM vtiger_profile", array());
        $profileIds = array();
        while ($row = $adb->fetch_array($profileResult)) {
            $profileIds[] = $row['profileid'];
        }

        // 有効なエンティティモジュールを取得
        $tabResult = $adb->pquery("SELECT tabid, name FROM vtiger_tab WHERE isentitytype = 1 AND presence IN (0, 2)", array());

        while ($tabRow = $adb->fetch_array($tabResult)) {
            $tabId = $tabRow['tabid'];
            $moduleName = $tabRow['name'];

            $moduleModel = Vtiger_Module_Model::getInstance($moduleName);
            if (!$moduleModel) {
                continue;
            }

            // モジュールがサポートするアクションを取得
            $supportedActionNames = $moduleModel->getUtilityActionsNames();
            $supportedActionIds = array();

            foreach ($supportedActionNames as $actionName) {
                $actionId = getActionid($actionName);
                if ($actionId !== '' && $actionId !== null && $actionId !== false) {
                    $supportedActionIds[] = (int)$actionId;

                    // 各プロファイルに対して、不足しているレコードを実際の画面表示実績に基づいて追加
                    foreach ($profileIds as $profileId) {
                        // 1. 管理者プロファイル（profileid = 1）: 全機能許可(0/チェックON)
                        if ($profileId == 1) {
                            $defaultPermission = 0;
                        // 2. その他全プロファイル（営業・サポート・ゲスト等）: もともと実際の画面に出ていたかどうかを厳密に判定
                        } else {
                            if ($actionId == 8) {
                                // 【マージ(8)】: 「重複の検出(10)」と「編集(1)」の両方が許可されていた場合のみ画面に出ていた
                                $dupCheckSql = "SELECT permission FROM vtiger_profile2utility WHERE profileid = ? AND tabid = ? AND activityid = 10 LIMIT 1";
                                $dupCheckResult = $adb->pquery($dupCheckSql, array($profileId, $tabId));
                                $dupPerm = ($dupCheckResult && $adb->num_rows($dupCheckResult) > 0) ? (int)$adb->query_result($dupCheckResult, 0, 'permission') : 0;

                                $editCheckSql = "SELECT permissions FROM vtiger_profile2standardpermissions WHERE profileid = ? AND tabid = ? AND operation = 1 LIMIT 1";
                                $editCheckResult = $adb->pquery($editCheckSql, array($profileId, $tabId));
                                $editPerm = ($editCheckResult && $adb->num_rows($editCheckResult) > 0) ? (int)$adb->query_result($editCheckResult, 0, 'permissions') : 0;

                                // 画面に出ていた場合（重複検出と編集の両方が許可:0）は0(チェックON)、画面に出ていなかった場合は1(チェックOFF)
                                $defaultPermission = ($dupPerm === 0 && $editPerm === 0) ? 0 : 1;

                            } elseif ($actionId == 10) {
                                // 【重複の検出(10)】: 「編集(1)」が許可されていた場合に画面に出ていた
                                $editCheckSql = "SELECT permissions FROM vtiger_profile2standardpermissions WHERE profileid = ? AND tabid = ? AND operation = 1 LIMIT 1";
                                $editCheckResult = $adb->pquery($editCheckSql, array($profileId, $tabId));
                                $editPerm = ($editCheckResult && $adb->num_rows($editCheckResult) > 0) ? (int)$adb->query_result($editCheckResult, 0, 'permissions') : 0;
                                $defaultPermission = ($editPerm === 0) ? 0 : 1;

                            } elseif ($actionId == 5) {
                                // 【インポート(5)】: 「新規作成(7)」が許可されていた場合に画面に出ていた
                                $createCheckSql = "SELECT permissions FROM vtiger_profile2standardpermissions WHERE profileid = ? AND tabid = ? AND operation = 7 LIMIT 1";
                                $createCheckResult = $adb->pquery($createCheckSql, array($profileId, $tabId));
                                $createPerm = ($createCheckResult && $adb->num_rows($createCheckResult) > 0) ? (int)$adb->query_result($createCheckResult, 0, 'permissions') : 0;
                                $defaultPermission = ($createPerm === 0) ? 0 : 1;

                            } elseif ($actionId == 6) {
                                // 【エクスポート(6)】: 「詳細表示(4)」が許可されていた場合に画面に出ていた
                                $viewCheckSql = "SELECT permissions FROM vtiger_profile2standardpermissions WHERE profileid = ? AND tabid = ? AND operation = 4 LIMIT 1";
                                $viewCheckResult = $adb->pquery($viewCheckSql, array($profileId, $tabId));
                                $viewPerm = ($viewCheckResult && $adb->num_rows($viewCheckResult) > 0) ? (int)$adb->query_result($viewCheckResult, 0, 'permissions') : 0;
                                $defaultPermission = ($viewPerm === 0) ? 0 : 1;

                            } else {
                                $defaultPermission = 0;
                            }
                        }

                        $checkSql = "SELECT 1 FROM vtiger_profile2utility WHERE profileid = ? AND tabid = ? AND activityid = ?";
                        $checkResult = $adb->pquery($checkSql, array($profileId, $tabId, $actionId));
                        if ($adb->num_rows($checkResult) == 0) {
                            $adb->pquery(
                                "INSERT INTO vtiger_profile2utility (profileid, tabid, activityid, permission) VALUES (?, ?, ?, ?)",
                                array($profileId, $tabId, $actionId, $defaultPermission)
                            );
                        }
                    }
                }
            }

            // 非対応アクションの不要データを削除
            $targetActionIds = array(5, 6, 8, 10);
            $unsupportedActionIds = array_values(array_diff($targetActionIds, $supportedActionIds));

            if (!empty($unsupportedActionIds)) {
                $deleteSql = "DELETE FROM vtiger_profile2utility WHERE tabid = ? AND activityid IN (" . generateQuestionMarks($unsupportedActionIds) . ")";
                $deleteParams = array_merge(array($tabId), $unsupportedActionIds);
                $adb->pquery($deleteSql, $deleteParams);
            }
        }

        // 全アクティブユーザーの権限キャッシュファイルを再生成
        require_once 'modules/Users/CreateUserPrivilegeFile.php';
        $userRes = $adb->pquery("SELECT id FROM vtiger_users WHERE status = 'Active'", array());
        while ($uRow = $adb->fetch_array($userRes)) {
            createUserPrivilegesfile($uRow['id']);
        }

        $this->log("プロファイル設定のユーティリティアクション同期およびユーザー権限キャッシュ再生成が正常に完了しました（vtiger_profile2utility）");
    }
}
