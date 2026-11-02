<?php
/**
 * Admin — create / edit a livestock lot: details, pricing, photos, health records, weigh sessions.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$id = query_int('id');
$l = $id ? db_one('SELECT * FROM lots WHERE id = ?', [$id]) : null;
if ($id && !$l) {
    require_admin();
    http_response_code(404);
    exit('Lot not found.');
}
// Next free number after the highest GXR-L… in use (a count would repeat a number once a lot is deleted)
$maxNo = (int) db_val("SELECT MAX(CAST(SUBSTRING(lot_no, 6) AS UNSIGNED)) FROM lots WHERE lot_no REGEXP '^GXR-L[0-9]+$'");
$nextNo = 'GXR-L' . str_pad((string) ($maxNo + 1), 3, '0', STR_PAD_LEFT);
$l ??= ['id' => 0, 'lot_no' => $nextNo, 'slug' => '', 'title' => '', 'breed_id' => '', 'category_id' => '', 'sex' => 'mixed', 'age_months_min' => '', 'age_months_max' => '',
        'tag_no' => '', 'qty_total' => '', 'qty_available' => 0, 'avg_weight_kg' => '', 'pricing_mode' => 'per_head', 'price_cents' => null, 'deposit_pct' => 20,
        'cost_cents' => null, 'available_from' => date('Y-m-d'), 'location' => '', 'short_description' => '', 'description_md' => '', 'health_summary' => '',
        'video_url' => '', 'status' => 'draft', 'is_featured' => 0, 'meta_title' => '', 'meta_description' => ''];
$health = $id ? db_all('SELECT * FROM lot_health_records WHERE lot_id = ? ORDER BY record_date DESC, id DESC', [$id]) : [];
$weighs = $id ? db_all('SELECT * FROM weigh_sessions WHERE lot_id = ? ORDER BY weighed_on DESC, id DESC', [$id]) : [];
$media = $id ? db_col('SELECT media_id FROM lot_media WHERE lot_id = ? ORDER BY sort_order, id', [$id]) : [];
$breeds = array_column(db_all('SELECT id, name FROM breeds ORDER BY sort_order, name'), 'name', 'id');
$cats = array_column(db_all('SELECT id, name FROM livestock_categories ORDER BY sort_order, name'), 'name', 'id');
$subs = $id ? (int) db_val('SELECT COUNT(DISTINCT email) FROM livestock_alerts WHERE is_active = 1 AND confirmed_at IS NOT NULL AND (breed_id IS NULL OR breed_id = ?) AND (category_id IS NULL OR category_id = ?)', [$l['breed_id'], $l['category_id']]) : 0;
$types = ['vaccination' => 'Vaccination', 'dosing' => 'Dosing', 'treatment' => 'Treatment', 'inspection' => 'Inspection', 'other' => 'Other'];

$healthRow = static function (string $i, array $h) use ($types): string {
    ob_start(); ?>
    <div class="repeat-row" data-repeat-row>
      <div class="field"><label>Date</label><input class="input" type="date" name="health[<?= e($i) ?>][record_date]" value="<?= e($h['record_date'] ?? date('Y-m-d')) ?>"></div>
      <div class="field"><label>Type</label><select class="select" name="health[<?= e($i) ?>][type]"><?php foreach ($types as $k => $t): ?><option value="<?= e($k) ?>"<?= ($h['type'] ?? '') === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Product</label><input class="input" name="health[<?= e($i) ?>][product_used]" value="<?= e($h['product_used'] ?? '') ?>"></div>
      <div class="field"><label>Notes</label><input class="input" name="health[<?= e($i) ?>][notes]" value="<?= e($h['notes'] ?? '') ?>"></div>
      <div class="field"><label>Withdrawal until</label><input class="input" type="date" name="health[<?= e($i) ?>][withdrawal_until]" value="<?= e($h['withdrawal_until'] ?? '') ?>"></div>
      <button class="btn btn-ghost btn-icon" type="button" data-repeat-remove>✕</button>
    </div>
    <?php return (string) ob_get_clean();
};
$weighRow = static function (string $i, array $w): string {
    ob_start(); ?>
    <div class="repeat-row" data-repeat-row>
      <div class="field"><label>Date</label><input class="input" type="date" name="weighs[<?= e($i) ?>][weighed_on]" value="<?= e($w['weighed_on'] ?? date('Y-m-d')) ?>"></div>
      <div class="field"><label>Head weighed</label><input class="input" type="number" min="1" name="weighs[<?= e($i) ?>][head_count]" value="<?= e($w['head_count'] ?? '') ?>"></div>
      <div class="field"><label>Average kg</label><input class="input" inputmode="decimal" name="weighs[<?= e($i) ?>][avg_weight_kg]" value="<?= e($w['avg_weight_kg'] ?? '') ?>"></div>
      <div class="field"><label>Notes</label><input class="input" name="weighs[<?= e($i) ?>][notes]" value="<?= e($w['notes'] ?? '') ?>"></div>
      <button class="btn btn-ghost btn-icon" type="button" data-repeat-remove>✕</button>
    </div>
    <?php return (string) ob_get_clean();
};

admin_start($id ? 'Edit lot ' . $l['lot_no'] : 'New livestock lot', 'lots', ['perm' => 'lots.manage']);
?>
<form data-admin-form data-api="/admin/api/lots.php" data-action="save" novalidate>
  <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
  <div class="adm-bar">
    <a class="btn btn-sm btn-ghost" href="/admin/lots.php"><svg class="icon icon-sm"><use href="#i-arrow-left"/></svg>Livestock</a>
    <div class="row">
      <?php if ($id && in_array($l['status'], ['available', 'coming_soon'], true)): ?>
        <button class="btn btn-sm btn-green" type="button" data-admin-act="/admin/api/lots.php" data-payload='<?= e(json_encode(['action' => 'notify', 'id' => (int) $l['id']])) ?>'
                data-confirm="Email <?= $subs ?> subscriber<?= $subs === 1 ? '' : 's' ?> about this lot?" data-ok="Send alert"><svg class="icon icon-sm"><use href="#i-bell"/></svg>Notify <?= $subs ?> subscriber<?= $subs === 1 ? '' : 's' ?></button>
      <?php endif; ?>
      <?php if ($id): ?><a class="btn btn-sm" href="/ranch/lot.php?slug=<?= e($l['slug']) ?>" target="_blank" rel="noopener">View on site ↗</a><?php endif; ?>
    </div>
  </div>

  <section class="card card-pad adm-section">
    <h2 class="h4 mb-2">The animals</h2>
    <div class="form-grid">
      <?= af_text('title', 'Title', $l['title'], ['required' => true, 'id' => 'l-title', 'placeholder' => 'Dorper weaner lambs']) ?>
      <?= af_text('lot_no', 'Lot number', $l['lot_no'], ['required' => true]) ?>
      <?= af_select('breed_id', 'Breed', $breeds, $l['breed_id'], ['required' => true, 'empty' => 'Choose…']) ?>
      <?= af_select('category_id', 'Type', $cats, $l['category_id'], ['required' => true, 'empty' => 'Choose…']) ?>
      <?= af_select('sex', 'Sex', ['mixed' => 'Mixed', 'ewe' => 'Ewes', 'ram' => 'Rams', 'wether' => 'Wethers'], $l['sex']) ?>
      <?= af_text('tag_no', 'Tag number (single animals)', $l['tag_no'], ['optional' => true]) ?>
      <?= af_text('age_months_min', 'Age from (months)', (string) $l['age_months_min'], ['type' => 'number', 'min' => 0, 'optional' => true]) ?>
      <?= af_text('age_months_max', 'Age to (months)', (string) $l['age_months_max'], ['type' => 'number', 'min' => 0, 'optional' => true]) ?>
      <?php if ($id): ?>
        <div class="field"><label>Head in lot</label><input class="input" type="number" name="qty_total" min="1" value="<?= (int) $l['qty_total'] ?>"><span class="hint"><?= (int) $l['qty_available'] ?> still available · change availability in <a class="link" href="/admin/inventory.php?tab=lots">Inventory</a></span></div>
      <?php else: ?>
        <?= af_text('qty_total', 'Number of head', (string) $l['qty_total'], ['type' => 'number', 'min' => 1, 'required' => true]) ?>
      <?php endif; ?>
      <?= af_text('avg_weight_kg', 'Average live weight (kg)', (string) $l['avg_weight_kg'], ['inputmode' => 'decimal', 'hint' => 'Updated automatically from the latest weighing']) ?>
      <?= af_text('location', 'Camp / location', $l['location'], ['optional' => true]) ?>
      <?= af_text('available_from', 'Available from', (string) $l['available_from'], ['type' => 'date']) ?>
    </div>
  </section>

  <section class="card card-pad adm-section">
    <h2 class="h4 mb-2">Price &amp; reservation</h2>
    <div class="form-grid">
      <?= af_select('pricing_mode', 'Pricing', ['per_head' => 'Per head', 'per_kg' => 'Per kg live weight (final at weighing)'], $l['pricing_mode']) ?>
      <?= af_money('price', 'Price (R per head or per kg)', $l['price_cents'] !== null ? (int) $l['price_cents'] : null, ['required' => true]) ?>
      <?= af_text('deposit_pct', 'Deposit to reserve (%)', (string) $l['deposit_pct'], ['type' => 'number', 'min' => 0, 'max' => 100, 'hint' => '0 = reserve without paying online']) ?>
      <?= af_money('cost', 'Your cost per head (for margins)', $l['cost_cents'] !== null ? (int) $l['cost_cents'] : null, ['optional' => true]) ?>
      <?= af_select('status', 'Status', ['draft' => 'Draft (hidden)', 'coming_soon' => 'Coming soon', 'available' => 'Available', 'reserved' => 'Reserved (few left)', 'sold_out' => 'Sold', 'archived' => 'Archived'], $l['status']) ?>
      <?= af_check('is_featured', 'Feature on the Ranch home page', (bool) $l['is_featured']) ?>
    </div>
  </section>

  <section class="card card-pad adm-section">
    <h2 class="h4 mb-1">Photos</h2>
    <p class="muted small">Landscape photos look best (shown 16:9). Tap ◎ to choose what stays in frame.</p>
    <div data-media-manager data-name="media_ids">
      <div class="media-grid">
        <?php foreach ($media as $mid): ?>
          <div class="media-item" draggable="true" data-id="<?= (int) $mid ?>"><img src="<?= e(media_url((int) $mid, 480, 'wide') ?? '') ?>" alt="">
            <span class="badge badge-solid mi-first" hidden>Main</span>
            <div class="mi-actions"><button type="button" data-focal="<?= (int) $mid ?>">◎</button><button type="button" data-media-remove>✕</button></div></div>
        <?php endforeach; ?>
        <label class="media-add"><input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden><svg class="icon icon-lg"><use href="#i-upload"/></svg>Add photos</label>
      </div>
    </div>
    <div class="form-grid mt-2"><?= af_text('video_url', 'YouTube video link', $l['video_url'], ['optional' => true, 'span' => true, 'placeholder' => 'https://youtu.be/…']) ?></div>
  </section>

  <section class="card card-pad adm-section">
    <h2 class="h4 mb-2">Description</h2>
    <div class="form-grid">
      <?= af_textarea('short_description', 'Short description (on cards)', $l['short_description'], ['span' => true, 'rows' => 2, 'maxlength' => 500]) ?>
      <?= af_markdown('description_md', 'Full description', $l['description_md'], ['span' => true]) ?>
      <?= af_textarea('health_summary', 'Health summary', $l['health_summary'], ['span' => true, 'rows' => 2, 'optional' => true]) ?>
    </div>
  </section>

  <section class="card card-pad adm-section">
    <div class="row-between mb-2"><h2 class="h4">Health records</h2><button class="btn btn-sm" type="button" data-repeat-add="health">＋ Add record</button></div>
    <div data-repeat="health"><?php foreach ($health as $i => $h): ?><?= $healthRow((string) $i, $h) ?><?php endforeach; ?></div>
    <template data-repeat-template="health"><?= $healthRow('__i__', []) ?></template>
  </section>

  <section class="card card-pad adm-section">
    <div class="row-between mb-2"><h2 class="h4">Weighings</h2><button class="btn btn-sm" type="button" data-repeat-add="weighs">＋ Add weighing</button></div>
    <p class="muted small">Shown as a growth chart on the lot page. The latest weighing updates the average weight.</p>
    <div data-repeat="weighs"><?php foreach ($weighs as $i => $w): ?><?= $weighRow((string) $i, $w) ?><?php endforeach; ?></div>
    <template data-repeat-template="weighs"><?= $weighRow('__i__', []) ?></template>
  </section>

  <section class="card card-pad adm-section">
    <h2 class="h4 mb-2">SEO</h2>
    <div class="form-grid">
      <?= af_text('slug', 'Web address (slug)', $l['slug'], ['data' => ['slug-from' => 'l-title'], 'optional' => true]) ?>
      <?= af_text('meta_title', 'Page title', $l['meta_title'], ['optional' => true]) ?>
      <?= af_textarea('meta_description', 'Meta description', $l['meta_description'], ['optional' => true, 'span' => true, 'rows' => 2]) ?>
    </div>
  </section>

  <div class="save-bar"><a class="btn btn-ghost" href="/admin/lots.php">Cancel</a><button class="btn btn-primary btn-lg" type="submit">Save lot</button></div>
</form>
<?php admin_end(); ?>
