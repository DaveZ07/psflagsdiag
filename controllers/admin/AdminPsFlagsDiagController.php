<?php
class AdminPsFlagsDiagController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
    }

    public function initContent()
    {
        parent::initContent();

        $defaultDir = rtrim(_PS_THEME_DIR_, '/');
        $scanDirReq = Tools::getValue('scan_dir');
        $scanDir = $scanDirReq ? rtrim($scanDirReq, '/') : $defaultDir;
        $shopRoot = rtrim(_PS_ROOT_DIR_, '/');
        $realScan = realpath($scanDir);
        if ($realScan === false || strpos($realScan, $shopRoot) !== 0 || !is_dir($realScan)) {
            $scanDir = $defaultDir;
            $realScan = realpath($scanDir);
        }

        $includeModules = (string)Tools::getValue('include_modules') === '1';
        $scanOnlyActive = (string)Tools::getValue('scan_active') === '1';
        $scanLimit = (int)Tools::getValue('scan_limit', 0);
        $cssPattern = Tools::getValue('flag_css_pattern', '(?:\.product-flag(?:--[a-z0-9\-]+)?|\.(?:new|on-?sale|online-?only|pack))');

        $scanShopId = (int)Tools::getValue('scan_shop_id', 0);
        if ($scanShopId > 0) {
            \Shop::setContext(\Shop::CONTEXT_SHOP, $scanShopId);
            $this->context->shop = new \Shop($scanShopId);
        }

    // ensure we collect presenter flags first so we can include any dynamic flags
    $shopCtxOk = $this->hasValidShopContext();
    $presenterAgg = $shopCtxOk ? $this->collectFlagsFromPresenter($scanOnlyActive, 200, $scanLimit) : ['stats'=>[], 'total_scanned'=>0];

    // core flags summary may be extended with dynamic flags discovered by presenter
    $coreFlags = $this->getCoreFlagsSummary($presenterAgg['stats']);
    $availability = $this->getAvailabilitySummary();

        $themeScan = $this->scanForFlagClasses($realScan, $cssPattern);
        $moduleScan = $includeModules ? $this->scanForFlagClasses(_PS_MODULE_DIR_, $cssPattern) : [];
        $moduleTplScan = $includeModules ? $this->scanTplForFlagsAndClasses(_PS_MODULE_DIR_, $cssPattern) : ['tpl_keys'=>[], 'tpl_classes'=>[], 'files'=>[]];

        $tplScan = $this->scanTplForFlagsAndClasses($realScan, $cssPattern);

        $html  = '<div style="padding:16px">';
        $html .= '<h2>Flags Diagnostic</h2>';

        $html .= '<form method="get" style="margin:10px 0;padding:10px;border:1px solid #dde">';
        $html .= '<input type="hidden" name="controller" value="AdminPsFlagsDiag"/>';
        $html .= '<input type="hidden" name="token" value="'.Tools::safeOutput(Tools::getValue('token')).'"/>';
        $html .= '<label><strong>Scan directory</strong> <input type="text" name="scan_dir" style="width:60%" value="'.Tools::safeOutput($scanDir).'" /></label> ';
        $html .= '<label style="margin-left:12px;">Include modules <input type="checkbox" name="include_modules" value="1" '.($includeModules?'checked':'').' /></label> ';
        $html .= '<label style="margin-left:12px;">Only active <input type="checkbox" name="scan_active" value="1" '.($scanOnlyActive?'checked':'').' /></label> ';
        $html .= '<label style="margin-left:12px;">Limit <input type="number" name="scan_limit" min="0" step="100" style="width:90px" value="'.(int)$scanLimit.'" /></label> ';
        $html .= '<label style="margin-left:12px;">CSS pattern <input type="text" name="flag_css_pattern" style="width:260px" value="'.Tools::safeOutput($cssPattern).'" /></label> ';

        $html .= '<label style="margin-left:12px;">Shop ';
        $html .= '<select name="scan_shop_id" style="min-width:220px">';
        $shops = \Shop::getShops(false, null, true);
        $html .= '<option value="0">Current context</option>';
        if (is_array($shops)) {
            foreach ($shops as $sid) {
                $s = new \Shop((int)$sid);
                $sel = ($scanShopId == (int)$sid) ? ' selected' : '';
                $html .= '<option value="'.(int)$sid.'"'.$sel.'>#'.(int)$sid.' - '.htmlspecialchars($s->name).'</option>';
            }
        }
        $html .= '</select></label>';

        $html .= '<button class="btn btn-primary" style="margin-left:12px;">Scan</button>';
        $html .= '<div style="font-size:12px;color:#666;margin-top:6px">Default theme dir: <code>'.Tools::safeOutput($defaultDir).'</code></div>';
        $html .= '</form>';

        if (!$shopCtxOk) {
            $html .= '<div class="alert alert-warning">No valid shop context. Select a specific shop in the selector above or switch Multistore to a shop.</div>';
        }

        $html .= '<h3>Debug snapshot</h3>';
        $html .= '<p><strong>scan_dir:</strong> <code>'.Tools::safeOutput($realScan).'</code>';
        $html .= ' | <strong>coreFlags:</strong> yes';
        $html .= ' | <strong>availability:</strong> yes';
        $html .= ' | <strong>presenter flags found:</strong> '.count($presenterAgg['stats']);
        $html .= ' | <strong>themeScan:</strong> '.(is_array($themeScan)?count($themeScan):'n/a');
        $html .= ' | <strong>moduleScan:</strong> '.(is_array($moduleScan)?count($moduleScan):'n/a').' | <strong>moduleTplScan:</strong> '.(is_array($moduleTplScan)?(count($moduleTplScan['tpl_keys'])+count($moduleTplScan['tpl_classes'])):'n/a');
        $html .= ' | <strong>shop_id:</strong> '.($this->context->shop && $this->context->shop->id ? (int)$this->context->shop->id : 0).'</p>';

        $html .= '<h3>Dynamic flags from Presenter (core + modules + hooks)</h3>';
        $html .= '<p><small>Scanned products: '.(int)$presenterAgg['total_scanned'].'</small></p>';
        $html .= '<table class="table" border="1" cellpadding="6"><thead><tr><th>Key</th><th>Count</th><th>Sample product IDs</th></tr></thead><tbody>';
        if (!empty($presenterAgg['stats'])) {
            foreach ($presenterAgg['stats'] as $k => $row) {
                $html .= '<tr>';
                $html .= '<td><code>'.htmlspecialchars($k).'</code></td>';
                $html .= '<td>'.(int)$row['count'].'</td>';
                $html .= '<td><small>'.htmlspecialchars(implode(',', $row['sample_ids'])).'</small></td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="3"><em>'.($shopCtxOk ? 'No flags returned by presenter.' : 'Presenter scan skipped due to missing shop context.').'</em></td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<h3>Core logical flags (diagnostic)</h3>';
        $html .= '<table class="table" style="width:100%;border-collapse:collapse" border="1" cellpadding="6">';
        $html .= '<thead><tr><th>Key</th><th>Label</th><th>Hint</th><th>Products</th><th>Source</th></tr></thead><tbody>';
        foreach ($coreFlags as $row) {
            $html .= '<tr>';
            $html .= '<td><code>'.htmlspecialchars($row['key']).'</code></td>';
            $html .= '<td>'.htmlspecialchars($row['label']).'</td>';
            $html .= '<td>'.htmlspecialchars($row['hint']).'</td>';
            $html .= '<td>'.(int)$row['count'].'</td>';
            $html .= '<td><small>'.htmlspecialchars($row['source']).'</small></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<h3>Availability overview</h3>';
        $html .= '<table class="table" border="1" cellpadding="6"><tr><th>In stock</th><td>'.(int)($availability['in_stock'] ?? 0).'</td></tr>';
        $html .= '<tr><th>Out of stock</th><td>'.(int)($availability['out_of_stock'] ?? 0).'</td></tr></table>';

        $html .= '<h3>Theme badge classes (CSS)</h3>';
        $html .= '<table class="table" style="width:100%;border-collapse:collapse" border="1" cellpadding="6">';
        $html .= '<thead><tr><th>Class</th><th>Color</th><th>Background</th><th>Border</th><th>Files</th></tr></thead><tbody>';
        if (!empty($themeScan)) {
            foreach ($themeScan as $f) {
                $html .= '<tr>';
                $html .= '<td><code>.'.htmlspecialchars($f['class']).'</code></td>';
                $html .= '<td><code>'.htmlspecialchars($f['color'] ?? '-').'</code></td>';
                $html .= '<td><code>'.htmlspecialchars($f['background'] ?? '-').'</code></td>';
                $html .= '<td><code>'.htmlspecialchars($f['border'] ?? '-').'</code></td>';
                $html .= '<td><small>'.htmlspecialchars(implode(', ', $f['files'] ?? [])).'</small></td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="5"><em>No classes found</em></td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<h3>Template scan (TPL)</h3>';
        $html .= '<table class="table" border="1" cellpadding="6"><thead><tr><th>Key (from tpl)</th></tr></thead><tbody>';
        if (!empty($tplScan['tpl_keys'])) {
            foreach ($tplScan['tpl_keys'] as $k) {
                $html .= '<tr><td><code>'.htmlspecialchars($k).'</code></td></tr>';
            }
        } else {
            $html .= '<tr><td><em>No $product.flags.* keys found</em></td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<table class="table" border="1" cellpadding="6"><thead><tr><th>Class (from tpl)</th></tr></thead><tbody>';
        if (!empty($tplScan['tpl_classes'])) {
            foreach ($tplScan['tpl_classes'] as $cls) {
                $html .= '<tr><td><code>.'.htmlspecialchars($cls).'</code></td></tr>';
            }
        } else {
            $html .= '<tr><td><em>No classes in tpl</em></td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<h3>Modules template scan (TPL)</h3>';
        $html .= '<table class="table" border="1" cellpadding="6"><thead><tr><th>Key (from tpl in modules)</th></tr></thead><tbody>';
        if (!empty($moduleTplScan['tpl_keys'])) {
            foreach ($moduleTplScan['tpl_keys'] as $k) {
                $html .= '<tr><td><code>' . htmlspecialchars($k) . '</code></td></tr>';
            }
        } else { $html .= '<tr><td><em>No $product.flags.* keys in modules tpl</em></td></tr>'; }
        $html .= '</tbody></table>';
        $html .= '<table class="table" border="1" cellpadding="6"><thead><tr><th>Class (from tpl in modules)</th></tr></thead><tbody>';
        if (!empty($moduleTplScan['tpl_classes'])) {
            foreach ($moduleTplScan['tpl_classes'] as $cls) {
                $html .= '<tr><td><code>.' . htmlspecialchars($cls) . '</code></td></tr>';
            }
        } else { $html .= '<tr><td><em>No classes in modules tpl</em></td></tr>'; }
        $html .= '</tbody></table>';

        $html .= '</div>';

        $this->content = $html;
        $this->context->smarty->assign('content', $html);
    }

    private function hasValidShopContext()
    {
        return ($this->context->shop && (int)$this->context->shop->id > 0);
    }

    private function ensureFrontLikeContext()
    {
        $ctx = \Context::getContext();

        if (!$this->hasValidShopContext()) {
            $idShop = (int)\Shop::getContextShopID();
            if (!$idShop) {
                $idShop = (int)\Configuration::get('PS_SHOP_DEFAULT');
            }
            if (!$idShop) {
                $ids = \Shop::getShops(true, null, true);
                if (is_array($ids) && !empty($ids)) { $idShop = (int)reset($ids); }
            }
            if ($idShop) {
                \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);
                $ctx->shop = new \Shop($idShop);
            }
        }

        if (!$ctx->shop || empty($ctx->shop->id)) {
            return null;
        }

        if (!$ctx->link) { $ctx->link = new \Link(); }

        if (!$ctx->language || empty($ctx->language->id)) {
            $idLang = (int)\Configuration::get('PS_LANG_DEFAULT');
            if (!$idLang) { $idLang = 1; }
            $ctx->language = new \Language($idLang);
        }

        if (!$ctx->currency || empty($ctx->currency->id)) {
            $idCurrency = (int)\Configuration::get('PS_CURRENCY_DEFAULT');
            if ($idCurrency) { $ctx->currency = \Currency::getCurrencyInstance($idCurrency); }
        }

        return $ctx;
    }

    private function collectFlagsFromPresenter($onlyActive = true, $batchSize = 200, $maxProducts = 0)
    {
        $ctx = $this->ensureFrontLikeContext();
        if ($ctx === null || !$ctx->shop || empty($ctx->shop->id)) {
            return ['stats'=>[], 'total_scanned'=>0];
        }
        $idLang = (int)$ctx->language->id;

        $presenter = new \PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductPresenter(
            new \PrestaShop\PrestaShop\Adapter\Image\ImageRetriever($ctx->link),
            $ctx->link,
            new \PrestaShop\PrestaShop\Adapter\Product\PriceFormatter(),
            new \PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever(),
            $ctx->getTranslator()
        );
        $settings = new \PrestaShop\PrestaShop\Core\Product\ProductPresentationSettings();

        $stats = [];
        $scanned = 0;
        $page = 0;

        do {
            $offset = $page * $batchSize;
            $rows = \Product::getProducts($idLang, $offset, $batchSize, 'id_product', 'ASC', false, $onlyActive, $ctx);
            if (!$rows) { break; }
            foreach ($rows as $raw) {
                $scanned++;
                $raw = (array)$raw;
                try {
                    $presented = $presenter->present($settings, $raw, $ctx->language);
                } catch (\Throwable $e) {
                    // if presenter throws for incomplete row, skip
                    $presented = null;
                }
                if (!empty($presented['flags']) && is_array($presented['flags'])) {
                    foreach ($presented['flags'] as $k => $flag) {
                        if (!isset($stats[$k])) { $stats[$k] = ['count' => 0, 'sample_ids' => []]; }
                        $stats[$k]['count']++;
                        $pid = isset($presented['id_product']) ? (int)$presented['id_product'] : (int)$raw['id_product'];
                        if ($pid && count($stats[$k]['sample_ids']) < 10) { $stats[$k]['sample_ids'][] = $pid; }
                    }
                }
                if ($maxProducts > 0 && $scanned >= $maxProducts) { break 2; }
            }
            $page++;
        } while (count($rows) === $batchSize);

        ksort($stats);
        return ['stats' => $stats, 'total_scanned' => $scanned];
    }

    private function getCoreFlagsSummary($presenterStats = [])
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;
        $nbDays = (int)Configuration::get('PS_NB_DAYS_NEW');
        $newCnt = (int)$db->getValue("SELECT COUNT(*) FROM {$prefix}product p WHERE DATEDIFF(NOW(), p.date_add) <= ".$nbDays);
        $onSaleFlagCnt = (int)$db->getValue("SELECT COUNT(*) FROM {$prefix}product p WHERE p.on_sale = 1");
        $specificCnt = (int)$db->getValue("
            SELECT COUNT(DISTINCT sp.id_product)
            FROM {$prefix}specific_price sp
            WHERE (sp.reduction > 0 OR sp.reduction_type IN ('amount','percentage'))
              AND (sp.`from` = '0000-00-00 00:00:00' OR sp.`from` <= NOW())
              AND (sp.`to`   = '0000-00-00 00:00:00' OR sp.`to`   >= NOW())
        ");
        $onlineCnt = (int)$db->getValue("SELECT COUNT(*) FROM {$prefix}product p WHERE p.online_only = 1");
        $packCnt = (int)$db->getValue("SELECT COUNT(DISTINCT id_product_pack) FROM {$prefix}pack");
        $oosCnt = (int)$db->getValue("SELECT COUNT(DISTINCT sa.id_product) FROM {$prefix}stock_available sa WHERE sa.quantity <= 0");
        $inCnt  = (int)$db->getValue("SELECT COUNT(DISTINCT sa.id_product) FROM {$prefix}stock_available sa WHERE sa.quantity > 0");
        $psBackorder = (int)Configuration::get('PS_ORDER_OUT_OF_STOCK');
        $backorderCnt = (int)$db->getValue("
            SELECT COUNT(DISTINCT sa.id_product)
            FROM {$prefix}stock_available sa
            WHERE sa.quantity <= 0
              AND (
                sa.out_of_stock IN (1,2)
                OR (sa.out_of_stock = 0 AND {$psBackorder} = 1)
              )
        ");

        $defaults = [
            ['key'=>'new','label'=>'New','hint'=>sprintf('PS_NB_DAYS_NEW = %d days',$nbDays),'count'=>$newCnt,'source'=>'date_add <= PS_NB_DAYS_NEW'],
            ['key'=>'on_sale','label'=>'On sale (flag)','hint'=>'product.on_sale = 1','count'=>$onSaleFlagCnt,'source'=>'product.on_sale'],
            ['key'=>'on_sale_specific_price','label'=>'On sale (specific price reduction)','hint'=>'Active specific price with reduction','count'=>$specificCnt,'source'=>'specific_price reduction'],
            ['key'=>'online_only','label'=>'Online only','hint'=>'product.online_only = 1','count'=>$onlineCnt,'source'=>'product.online_only'],
            ['key'=>'pack','label'=>'Pack','hint'=>'Product present as id_product_pack in ps_pack','count'=>$packCnt,'source'=>'ps_pack'],
            ['key'=>'out_of_stock','label'=>'Out of stock','hint'=>'stock_available.quantity <= 0','count'=>$oosCnt,'source'=>'stock_available'],
            ['key'=>'in_stock','label'=>'In stock','hint'=>'stock_available.quantity > 0','count'=>$inCnt,'source'=>'stock_available'],
            ['key'=>'oos_backorder_enabled','label'=>'OOS with backorders allowed','hint'=>'quantity <= 0 AND (sa.out_of_stock in (1,2) OR (sa.out_of_stock=0 AND PS_ORDER_OUT_OF_STOCK=1))','count'=>$backorderCnt,'source'=>'stock_available + config'],
        ];

        // merge dynamic flags discovered by presenter (if any)
        if (!empty($presenterStats) && is_array($presenterStats)) {
            $existingKeys = array_column($defaults, 'key');
            foreach ($presenterStats as $k => $stat) {
                if (!in_array($k, $existingKeys, true)) {
                    $defaults[] = [
                        'key' => $k,
                        'label' => ucfirst(str_replace(['_','-'], [' ', ' '], $k)),
                        'hint' => 'Detected dynamically by presenter',
                        'count' => isset($stat['count']) ? (int)$stat['count'] : 0,
                        'source' => 'presenter',
                    ];
                }
            }
        }

        return $defaults;
    }

    private function getAvailabilitySummary()
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;
        $outCnt = (int)$db->getValue("SELECT COUNT(*) FROM {$prefix}stock_available sa WHERE sa.quantity <= 0");
        $inCnt = (int)$db->getValue("SELECT COUNT(*) FROM {$prefix}stock_available sa WHERE sa.quantity > 0");
        return ['in_stock' => $inCnt, 'out_of_stock' => $outCnt];
    }

    private function scanForFlagClasses($dir, $pattern)
    {
        $files = $this->gatherFiles($dir, ['css','scss','sass']);
        return $this->extractFlagClassesAndColors($files, $dir, $pattern);
    }

    private function scanTplForFlagsAndClasses($dir, $pattern)
    {
        $result = ['tpl_keys'=>[], 'tpl_classes'=>[], 'files'=>[]];
        $files = $this->gatherFiles($dir, ['tpl','html','smarty']);
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) { continue; }
            if (preg_match_all('/\$product\.flags\.([a-zA-Z0-9_\-]+)/', $content, $m1) || preg_match('/\{foreach[^}]*\$product\.flags[^}]*\}/i', $content)) {
                foreach (array_unique($m1[1]) as $k) { $result['tpl_keys'][$k] = true; }
                $result['files'][] = str_replace($dir, '', $file);
            }
            $pat = '/'.str_replace('/', '\/', $pattern).'/i';
            if (preg_match_all('/class\s*=\s*"([^"]*)"/i', $content, $mc)) {
                foreach ($mc[1] as $classes) {
                    if (strpos($classes, 'product-flag') !== false) {
                        foreach (preg_split('/\s+/', trim($classes)) as $clsVal) {
                            if ($clsVal !== '' && $clsVal !== 'product-flag' && $clsVal !== 'product-flags') {
                                $result['tpl_classes'][$clsVal] = true;
                            }
                        }
                    }
                }
            }
            
            if (preg_match_all($pat, $content, $m2)) {
                foreach (array_unique($m2[0]) as $cls) { $result['tpl_classes'][$cls] = true; }
                $result['files'][] = str_replace($dir, '', $file);
            }
        }
        $result['tpl_keys'] = array_values(array_unique(array_keys($result['tpl_keys'])));
        sort($result['tpl_keys']);
        $result['tpl_classes'] = array_values(array_unique(array_keys($result['tpl_classes'])));
        sort($result['tpl_classes']);
        $result['files'] = array_values(array_unique($result['files']));
        return $result;
    }

    private function gatherFiles($root, array $exts)
    {
        $list = [];
        if (!is_dir($root)) { return $list; }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $lower = strtolower($path);
            if (strpos($lower, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR) !== false) { continue; }
            foreach ($exts as $ext) {
                if (preg_match('/\.' . preg_quote($ext, '/') . '$/i', $path)) {
                    $list[] = $path;
                    break;
                }
            }
        }
        return $list;
    }

    private function extractFlagClassesAndColors(array $files, $baseDir, $pattern)
    {
        $flags = [];
        $pat = '/'.str_replace('/', '\/', $pattern).'/i';
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) { continue; }
            if (preg_match_all($pat, $content, $m)) {
                foreach (array_unique($m[0]) as $cls) {
                    $name = ltrim($cls, '.');
                    if (!isset($flags[$name])) {
                        $flags[$name] = ['class'=>$name, 'files'=>[], 'color'=>null, 'background'=>null, 'border'=>null];
                    }
                    $rel = str_replace($baseDir, '', $file);
                    if (!in_array($rel, $flags[$name]['files'])) { $flags[$name]['files'][] = $rel; }

                    $sel = preg_quote($cls, '/');
                    if (preg_match('/'.$sel.'\s*[{][^}]{0,600}[}]/mi', $content, $block)) {
                        $b = $block[0];
                        if (preg_match('/color\s*:\s*([^;]+);/i', $b, $cm)) { $flags[$name]['color'] = trim($cm[1]); }
                        if (preg_match('/background(?:-color)?\s*:\s*([^;]+);/i', $b, $bm)) { $flags[$name]['background'] = trim($bm[1]); }
                        if (preg_match('/border(?:-color)?\s*:\s*([^;]+);/i', $b, $brm)) { $flags[$name]['border'] = trim($brm[1]); }
                    }
                }
            }
        }
        ksort($flags);
        return array_values($flags);
    }
}
