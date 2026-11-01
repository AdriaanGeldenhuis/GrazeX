<?php
/**
 * Admin — create / edit a Feeds product: details, photos, sizes & prices, bulk tiers,
 * nutrition, feeding calculator rates, SEO.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$id = query_int('id');
$p = $id ? db_one('SELECT * FROM products WHERE id = ?', [$id]) : null;
if ($id && !$p) {
    require_admin();
    http_response_code(404);
    exit('Product not found.');
}
$p ??= ['id' => 0, 'name' => '', 'slug' => '', 'category_id' => '', 'brand_id' => '', 'short_description' => '', 'description_md' => '', 'feeding_guide_md' => '',
        'reg_no' => '', 'zero_rate_eligible' => 0, 'is_featured' => 0, 'is_active' => 1, 'meta_title' => '', 'meta_description' => '', 'sort_order' => 0];
$variants = $id ? db_all('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order, id', [$id]) : [];
if (!$variants) {
    $variants = [['id' => '', 'sku' => '', 'name' => '', 'weight_grams' => 0, 'price_cents' => null, 'compare_at_cents' => null, 'special_price_cents' => null,
                  'special_from' => null, 'special_to' => null, 'cost_cents' => null, 'stock_qty' => 0, 'low_stock_threshold' => 5, 'is_active' => 1]];
}
$specs = $id ? db_all('SELECT * FROM product_specs WHERE product_id = ? ORDER BY sort_order, id', [$id]) : [];
$media = $id ? db_col('SELECT media_id FROM product_media WHERE product_id = ? ORDER BY sort_order, id', [$id]) : [];
$speciesSel = $id ? array_map('intval', db_col('SELECT species_id FROM product_species WHERE product_id = ?', [$id])) : [];
$calc = $id ? db_all('SELECT * FROM calculator_rules WHERE product_id = ? ORDER BY id', [$id]) : [];
$cats = array_column(db_all('SELECT id, name FROM categories ORDER BY sort_order, name'), 'name', 'id');
$brands = array_column(db_all('SELECT id, name FROM brands WHERE is_active = 1 ORDER BY name'), 'name', 'id');
$species = db_all('SELECT id, name FROM species ORDER BY sort_order');
$speciesOpts = array_column($species, 'name', 'id');
$tierText = static function (?int $variantId): string {
    if (!$variantId) {
        return '';
    }
    return implode(', ', array_map(static fn($t) => (int) $t['min_qty'] . '=' . number_format((int) $t['price_cents'] / 100, 2, '.', ''), PricingService::tiers($variantId)));
};
$dt = static fn(?string $v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';

$variantRow = static function (string $i, array $v) use ($tierText, $dt): string {
    $isNew = $v['id'] === '';
    ob_start(); ?>
    <div class="repeat-row" data-repeat-row style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr)) auto">
      <input type="hidden" name="variants[<?= e($i) ?>][id]" value="<?= e($v['id']) ?>">
      <div class="field"><label>Size / name</label><input class="input" name="variants[<?= e($i) ?>][name]" value="<?= e($v['name']) ?>" placeholder="50 kg bag" required></div>
      <div class="field"><label>SKU</label><input class="input" name="variants[<?= e($i) ?>][sku]" value="<?= e($v['sku']) ?>" placeholder="auto"></div>
      <div class="field"><label>Weight (kg)</label><input class="input" name="variants[<?= e($i) ?>][weight_kg]" inputmode="decimal" value="<?= $v['weight_grams'] ? e(rtrim(rtrim(number_format((int) $v['weight_grams'] / 1000, 3, '.', ''), '0'), '.')) : '' ?>"></div>
      <div class="field"><label>Price (R)</label><input class="input" name="variants[<?= e($i) ?>][price]" inputmode="decimal" value="<?= $v['price_cents'] !== null ? e(number_format((int) $v['price_cents'] / 100, 2, '.', '')) : '' ?>" required></div>
      <div class="field"><label>Special (R)</label><input class="input" name="variants[<?= e($i) ?>][special_price]" inputmode="decimal" value="<?= $v['special_price_cents'] !== null ? e(number_format((int) $v['special_price_cents'] / 100, 2, '.', '')) : '' ?>"></div>
      <div class="field"><label>Special from</label><input class="input" type="datetime-local" name="variants[<?= e($i) ?>][special_from]" value="<?= e($dt($v['special_from'])) ?>"></div>
      <div class="field"><label>Special until</label><input class="input" type="datetime-local" name="variants[<?= e($i) ?>][special_to]" value="<?= e($dt($v['special_to'])) ?>"></div>
      <div class="field"><label>Cost (R)</label><input class="input" name="variants[<?= e($i) ?>][cost]" inputmode="decimal" value="<?= $v['cost_cents'] !== null ? e(number_format((int) $v['cost_cents'] / 100, 2, '.', '')) : '' ?>"></div>
      <div class="field"><label>Bulk prices <span class="opt">qty=R</span></label><input class="input" name="variants[<?= e($i) ?>][tiers]" value="<?= e($tierText($v['id'] === '' ? null : (int) $v['id'])) ?>" placeholder="10=469, 30=449"></div>
      <div class="field"><label>Low stock at</label><input class="input" type="number" min="0" name="variants[<?= e($i) ?>][low_stock_threshold]" value="<?= (int) $v['low_stock_threshold'] ?>"></div>
      <?php if ($isNew): ?>
        <div class="field"><label>Opening stock</label><input class="input" type="number" min="0" name="variants[<?= e($i) ?>][opening_stock]" value="0"></div>
      <?php else: ?>
        <div class="field"><label>In stock</label><a class="input" style="display:flex;align-items:center" href="/admin/inventory.php?variant=<?= (int) $v['id'] ?>"><?= (int) $v['stock_qty'] ?> · adjust →</a></div>
      <?php endif; ?>
      <div class="field"><label>Visible</label><input type="hidden" name="variants[<?= e($i) ?>][is_active]" value="0"><label class="check"><input type="checkbox" name="variants[<?= e($i) ?>][is_active]" value="1" <?= (int) $v['is_active'] ? 'checked' : '' ?>> On</label></div>
      <button class="btn btn-ghost btn-icon" type="button" data-repeat-remove aria-label="Remove size" title="Remove (hidden if it has sales)">✕</button>
    </div>
    <?php return (string) ob_get_clean();
};

admin_start($id ? 'Edit product' : 'New product', 'products', ['perm' => 'products.manage']);
?>
<form data-admin-form data-api="/admin/api/products.php" data-action="save" novalidate>
  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
  <div class="adm-bar">
    <a class="btn btn-sm btn-ghost" href="/admin/products.php"><svg class="icon icon-sm"><use href="#i-arrow-left"/></svg>Products</a>
    <div class="row">
      <?php if ($id): ?><a class="btn btn-sm" href="/feeds/product.php?slug=<?= e($p['slug']) ?>" target="_blank" rel="noopener">View on site ↗</a><?php endif; ?>
      <?php if ($id): ?><button class="btn btn-sm btn-danger" type="button" data-admin-act="/admin/api/products.php" data-payload='<?= e(json_encode(['action' => 'delete', 'id' => (int) $p['id']])) ?>' data-confirm="Delete this product? If it has sales it will be hidden instead." data-ok="Delete" data-danger>Delete</button><?php endif; ?>
    </div>
  </div>

  <section class="card card-pad adm-section">
    <h2 class="h4 mb-2">Product</h2>
    <div class="form-grid">
      <?= af_text('name', 'Name', $p['name'], ['required' => true, 'id' => 'p-name']) ?>
      <?= af_text('slug', 'Web address (slug)', $p['slug'], ['data' => ['slug-from' => 'p-name'], 'hint' => '/feeds/product/' . ($p['slug'] ?: 'your-product')]) ?>
      <?= af_select('category_id', 'Category', $cats, $p['category_id'], ['required' => true, 'empty' => 'Choose…']) ?>
      <?= af_select('brand_id', 'Brand', $brands, $p['brand_id'], ['empty' => '— none —', 'optional' => true]) ?>
      <?= af_textarea('short_description', 'Short description (shown on cards and at the top)', $p['short_description'], ['span' => true, 'rows' => 2, 'maxlength' => 500]) ?>
      <div class="field span-2"><span class="label">Animals</span><div class="chips">
        <?php foreach ($species as $s): ?><label class="chip"><input type="checkbox" name="species[]" value="<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $speciesSel, true) ? 'checked' : '' ?>> <?= e($s['name']) ?></label><?php endforeach; ?>
      </div></div>
      <?= af_check('is_active', 'Visible in the shop', (bool) $p['is_active']) ?>
      <?= af_check('is_featured', 'Featured ("Farmers\' favourites")', (bool) $p['is_featured']) ?>
    </div>
  </section>

  <section class="card card-pad adm-section">
    <h2 class="h4 mb-1">Photos</h2>
    <p class="muted small">The first photo is the main one. Drag to reorder. Tap ◎ to set the focal point for 16:9 crops. Photos are converted to fast .webp automatically.</p>
    <div data-media-manager data-name="media_ids">
      <div class="media-grid">
        <?php foreach ($media as $mid): ?>
          <div class="media-item" draggable="true" data-id="<?= (int) $mid ?>"><img src="<?= e(media_url((int) $mid, 480, 'wide') ?? '') ?>" alt="">
            <span class="badge badge-solid mi-first" hidden>Main</span>
            <div class="mi-actions"><button type="button" data-focal="<?= (int) $mid ?>" title="Focal point">◎</button><button type="button" data-media-remove title="Remove">✕</button></div></div>
        <?php endforeach; ?>
        <label class="media-add"><input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden><svg class="icon icon-lg"><use href="#i-upload"/></svg>Add photos</label>
      </div>
    </div>
  </section>

  <section class="card card-pad adm-section">
    <div class="row-between mb-2"><h2 class="h4">Sizes, prices &amp; stock</h2><button class="btn btn-sm" type="button" data-repeat-add="variants"><svg class="icon icon-sm"><use href="#i-plus"/></svg>Add size</button></div>
    <p class="muted small">Prices include VAT. Stock is changed in <a class="link" href="/admin/inventory.php">Inventory</a> so every change is logged.</p>
    <div data-repeat="variants">
      <?php foreach ($variants as $i => $v): ?><?= $variantRow((string) $i, $v) ?><?php endforeach; ?>
    </div>
    <template data-repeat-template="variants"><?= $variantRow('__i__', ['id' => '', 'sku' => '', 'name' => '', 'weight_grams' => 0, 'price_cents' => null, 'compare_at_cents' => null, 'special_price_cents' => null, 'special_from' => null, 'special_to' => null, 'cost_cents' => null, 'stock_qty' => 0, 'low_stock_threshold' => 5, 'is_active' => 1]) ?></template>
  </section>

  <section class="card card-pad adm-section">
    <h2 class="h4 mb-2">Description &amp; feeding</h2>
    <div class="form-grid">
      <?= af_markdown('description_md', 'Description', $p['description_md'], ['span' => true]) ?>
      <?= af_markdown('feeding_guide_md', 'Feeding guide', $p['feeding_guide_md'], ['span' => true]) ?>
      <?= af_text('reg_no', 'Registration number (Act 36 of 1947)', $p['reg_no'], ['optional' => true, 'placeholder' => 'V12345']) ?>
      <?= af_check('zero_rate_eligible', 'Zero-rated for VAT-registered farmers (animal feed / remedy — confirm with your accountant)', (bool) $p['zero_rate_eligible']) ?>
    </div>
  </section>

  <section class="card card-pad adm-section">
    <div class="row-between mb-2"><h2 class="h4">Nutrition table</h2><button class="btn btn-sm" type="button" data-repeat-add="specs">＋ Add row</button></div>
    <div data-repeat="specs">
      <?php foreach ($specs as $i => $s): ?>
        <div class="repeat-row" data-repeat-row><div class="field"><label>Nutrient</label><input class="input" name="specs[<?= $i ?>][label]" value="<?= e($s['label']) ?>"></div>
          <div class="field"><label>Value</label><input class="input" name="specs[<?= $i ?>][value]" value="<?= e($s['value']) ?>"></div><button class="btn btn-ghost btn-icon" type="button" data-repeat-remove>✕</button></div>
      <?php endforeach; ?>
    </div>
    <template data-repeat-template="specs"><div class="repeat-row" data-repeat-row><div class="field"><label>Nutrient</label><input class="input" name="specs[__i__][label]" placeholder="Crude protein"></div>
      <div class="field"><label>Value</label><input class="input" name="specs[__i__][value]" placeholder="min 30%"></div><button class="btn btn-ghost btn-icon" type="button" data-repeat-remove>✕</button></div></template>
  </section>

  <section class="card card-pad adm-section">
    <div class="row-between mb-2"><h2 class="h4">Feed calculator</h2><button class="btn btn-sm" type="button" data-repeat-add="calc">＋ Add rate</button></div>
    <p class="muted small">Daily intake per animal — used by the "How many bags?" calculator.</p>
    <div data-repeat="calc">
      <?php foreach ($calc as $i => $c): ?>
        <div class="repeat-row" data-repeat-row>
          <div class="field"><label>Animal</label><select class="select" name="calc[<?= $i ?>][species_id]"><?php foreach ($speciesOpts as $sid => $sn): ?><option value="<?= (int) $sid ?>"<?= (int) $c['species_id'] === (int) $sid ? ' selected' : '' ?>><?= e($sn) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Type of animal</label><input class="input" name="calc[<?= $i ?>][animal_class]" value="<?= e($c['animal_class']) ?>"></div>
          <div class="field"><label>Grams / day</label><input class="input" type="number" min="1" name="calc[<?= $i ?>][grams]" value="<?= (int) $c['intake_grams_per_day'] ?>"></div>
          <div class="field"><label>Note</label><input class="input" name="calc[<?= $i ?>][notes]" value="<?= e($c['notes']) ?>"></div>
          <button class="btn btn-ghost btn-icon" type="button" data-repeat-remove>✕</button></div>
      <?php endforeach; ?>
    </div>
    <template data-repeat-template="calc"><div class="repeat-row" data-repeat-row>
      <div class="field"><label>Animal</label><select class="select" name="calc[__i__][species_id]"><?php foreach ($speciesOpts as $sid => $sn): ?><option value="<?= (int) $sid ?>"><?= e($sn) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Type of animal</label><input class="input" name="calc[__i__][animal_class]" placeholder="Ewes (maintenance)"></div>
      <div class="field"><label>Grams / day</label><input class="input" type="number" min="1" name="calc[__i__][grams]" placeholder="150"></div>
      <div class="field"><label>Note</label><input class="input" name="calc[__i__][notes]"></div>
      <button class="btn btn-ghost btn-icon" type="button" data-repeat-remove>✕</button></div></template>
  </section>

  <section class="card card-pad adm-section">
    <h2 class="h4 mb-2">Search engines (SEO)</h2>
    <div class="form-grid">
      <?= af_text('meta_title', 'Page title', $p['meta_title'], ['optional' => true, 'maxlength' => 191]) ?>
      <?= af_text('sort_order', 'Sort order', (string) $p['sort_order'], ['type' => 'number']) ?>
      <?= af_textarea('meta_description', 'Meta description', $p['meta_description'], ['optional' => true, 'span' => true, 'rows' => 2, 'maxlength' => 300]) ?>
    </div>
  </section>

  <div class="save-bar"><a class="btn btn-ghost" href="/admin/products.php">Cancel</a><button class="btn btn-primary btn-lg" type="submit">Save product</button></div>
</form>
<?php admin_end(); ?>
