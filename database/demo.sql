-- =====================================================================
-- GrazeX — OPTIONAL demo data
-- Run AFTER install.sql if you want to see the shop filled with sample
-- products and livestock lots. Everything here is example data:
-- edit or delete it in Admin before going live.
-- Registration numbers below are placeholders, NOT real Act 36 numbers.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+02:00';

INSERT INTO brands (id, slug, name) VALUES
(1, 'grazex', 'GrazeX'), (2, 'veld-pro', 'Veld Pro'), (3, 'agri-mix', 'Agri Mix');

INSERT INTO products (id, category_id, brand_id, slug, name, short_description, description_md, feeding_guide_md, reg_no, zero_rate_eligible, is_featured, sort_order) VALUES
(1, 1, 1, 'grazex-winter-protein-lick', 'GrazeX Winter Protein Lick', 'High-protein winter lick to keep sheep and cattle in condition on dry veld.',
 'A palatable **winter protein lick** that helps animals make better use of dry grazing.\n\n- Supports rumen function on low-quality roughage\n- Helps maintain body condition through winter\n- Free-choice feeding',
 'Provide free choice. Expected intake:\n\n- Sheep: 100–150 g/head/day\n- Cattle: 750–1 000 g/head/day\n\nAlways provide clean water. Introduce gradually over 7 days.', 'V00000 (demo)', 1, 1, 1),
(2, 2, 1, 'lamb-finisher-pellets', 'Lamb Finisher Pellets', 'Complete finishing ration for fast, efficient lamb growth.',
 'A balanced **complete finishing pellet** formulated for weaned lambs in the final growth phase.\n\n- High energy for efficient weight gain\n- Contains buffers to support rumen health\n- Consistent pellet quality, low dust',
 'Adapt lambs over 10–14 days. Full feed intake is roughly 3.5–4% of body weight per day (about 1.2–1.6 kg/head/day). Always provide roughage and clean water.', 'V00000 (demo)', 1, 1, 2),
(3, 2, 2, 'ewe-production-pellets', 'Ewe Production Pellets', 'Supplement for pregnant and lactating ewes.',
 'Supports ewes through late pregnancy and lactation for stronger lambs and better milk production.', 'Feed 300–500 g/head/day depending on grazing quality and stage of production.', 'V00000 (demo)', 1, 0, 3),
(4, 3, 3, 'mineral-block-20kg', 'Mineral Block 20kg', 'Weather-resistant mineral block for all livestock.',
 'A compressed **mineral block** with macro and trace minerals. Weather resistant for use in the camp.', 'Place one block per 20–30 sheep or 8–10 cattle.', 'V00000 (demo)', 1, 0, 4),
(5, 4, 1, 'lucerne-bale', 'Lucerne Bale', 'Leafy, green lucerne bales — excellent protein roughage.',
 'Locally sourced **lucerne** with a good leaf-to-stem ratio. Approximately 30–35 kg per bale.', 'Feed as part of the roughage ration. Introduce gradually to avoid bloat.', NULL, 0, 1, 5),
(6, 4, 1, 'eragrostis-teff-bale', 'Eragrostis (Teff) Bale', 'Soft, palatable grass hay for all livestock.',
 'Good-quality **Eragrostis teff** hay — ideal maintenance roughage. Approximately 25–30 kg per bale.', 'Feed free choice as roughage.', NULL, 0, 0, 6),
(7, 5, 2, 'lamb-creep-feed', 'Lamb Creep Feed 25kg', 'Highly palatable starter feed for young lambs.',
 'Gets lambs eating solid feed early for better weaning weights.', 'Offer from 2 weeks of age in a creep area only lambs can access.', 'V00000 (demo)', 1, 0, 7),
(8, 3, 3, 'coarse-salt-25kg', 'Coarse Salt 25kg', 'Coarse salt for licks and free-choice feeding.', 'Plain coarse salt.', 'Mix into licks as directed.', NULL, 1, 0, 8),
(9, 6, NULL, 'galvanised-feed-trough-2m', 'Galvanised Feed Trough 2m', 'Heavy-duty 2 m trough with legs — built to last.', 'Hot-dip galvanised steel trough with reinforced edges.', NULL, NULL, 0, 0, 9),
(10, 3, 2, 'vitamin-mineral-premix', 'Vitamin & Mineral Premix 5kg', 'Concentrated premix for home-mixed rations.', 'Concentrated vitamin and trace-mineral premix.', 'Mix 5 kg per ton of complete feed.', 'V00000 (demo)', 1, 0, 10);

INSERT INTO product_species (product_id, species_id) VALUES
(1,1),(1,2),(2,1),(3,1),(4,1),(4,2),(4,3),(5,1),(5,2),(5,5),(6,1),(6,2),(6,5),(7,1),(8,1),(8,2),(9,1),(9,2),(10,1),(10,2);

INSERT INTO product_variants (id, product_id, sku, name, weight_grams, price_cents, compare_at_cents, special_price_cents, special_from, special_to, cost_cents, stock_qty, low_stock_threshold, sort_order) VALUES
(1, 1, 'GXF-WPL-50', '50 kg bag', 50000, 49500, NULL, NULL, NULL, NULL, 39000, 46, 8, 1),
(2, 2, 'GXF-LFP-40', '40 kg bag', 40000, 38900, NULL, 35900, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 13 DAY, 30500, 64, 10, 1),
(3, 3, 'GXF-EPP-40', '40 kg bag', 40000, 36500, NULL, NULL, NULL, NULL, 29000, 22, 6, 1),
(4, 4, 'GXF-MB-20', '20 kg block', 20000, 27900, NULL, NULL, NULL, NULL, 21000, 4, 5, 1),
(5, 5, 'GXF-LUC-BALE', 'Bale (±32 kg)', 32000, 16500, NULL, NULL, NULL, NULL, 12000, 140, 20, 1),
(6, 6, 'GXF-TEF-BALE', 'Bale (±28 kg)', 28000, 11900, NULL, 10500, NOW() - INTERVAL 2 DAY, NOW() + INTERVAL 5 DAY, 8500, 95, 20, 1),
(7, 7, 'GXF-LCF-25', '25 kg bag', 25000, 31500, NULL, NULL, NULL, NULL, 25000, 18, 5, 1),
(8, 8, 'GXF-SALT-25', '25 kg bag', 25000, 9900, NULL, NULL, NULL, NULL, 6500, 0, 5, 1),
(9, 9, 'GXF-TRO-2M', '2 m trough', 0, 189900, NULL, NULL, NULL, NULL, 145000, 6, 2, 1),
(10, 10, 'GXF-PMX-5', '5 kg bag', 5000, 42500, NULL, NULL, NULL, NULL, 33000, 12, 4, 1),
(11, 2, 'GXF-LFP-1T', '1 ton (25 × 40 kg)', 1000000, 945000, NULL, NULL, NULL, NULL, 760000, 3, 1, 2),
(12, 1, 'GXF-WPL-25', '25 kg bag', 25000, 26500, NULL, NULL, NULL, NULL, 20800, 30, 6, 2);

INSERT INTO price_tiers (variant_id, min_qty, price_cents) VALUES
(1, 10, 46900), (1, 30, 44900),
(2, 10, 34900), (2, 25, 33900),
(5, 20, 15500), (5, 50, 14900),
(6, 20, 9900);

INSERT INTO product_specs (product_id, label, value, sort_order) VALUES
(1, 'Crude protein', 'min 30%', 1), (1, 'Urea', 'max 8%', 2), (1, 'Calcium', 'min 2%', 3), (1, 'Phosphorus', 'min 1%', 4),
(2, 'Crude protein', 'min 15%', 1), (2, 'Fat', 'min 2.5%', 2), (2, 'Fibre', 'max 12%', 3), (2, 'Moisture', 'max 12%', 4),
(3, 'Crude protein', 'min 14%', 1), (3, 'Fibre', 'max 14%', 2),
(4, 'Calcium', 'min 10%', 1), (4, 'Phosphorus', 'min 6%', 2), (4, 'Salt', 'max 30%', 3),
(5, 'Crude protein', '±18%', 1), (5, 'Bale weight', '±32 kg', 2),
(7, 'Crude protein', 'min 18%', 1);

INSERT INTO calculator_rules (product_id, species_id, animal_class, intake_grams_per_day, notes) VALUES
(1, 1, 'Sheep (winter maintenance)', 125, 'Free choice'),
(1, 2, 'Cattle (winter maintenance)', 850, 'Free choice'),
(2, 1, 'Lambs (finishing)', 1400, 'Full feed'),
(3, 1, 'Ewes (late pregnancy / lactation)', 400, NULL),
(5, 1, 'Sheep (roughage)', 1000, 'Part of roughage ration'),
(5, 2, 'Cattle (roughage)', 6000, 'Part of roughage ration'),
(7, 1, 'Lambs (creep, 2–8 weeks)', 150, NULL);

-- Livestock
INSERT INTO lots (id, lot_no, slug, title, breed_id, category_id, sex, age_months_min, age_months_max, tag_no, qty_total, qty_available, avg_weight_kg, pricing_mode, price_cents, deposit_pct, cost_cents, available_from, location, short_description, description_md, health_summary, status, is_featured, published_at) VALUES
(1, 'GXR-L001', 'dorper-weaner-lambs-gxr-l001', 'Dorper weaner lambs', 1, 1, 'mixed', 3, 4, NULL, 25, 25, 28.40, 'per_kg', 6200, 20, NULL, CURDATE(), 'Camp 4', 'Even group of healthy Dorper weaners, ready for finishing.',
 'An **even group** of Dorper weaner lambs from a single source, weaned and settled on irrigated pasture.\n\n- Vaccinated and dosed (see health records)\n- Weighed this week\n- Ideal for finishing on pasture or feedlot',
 'Vaccinated (pulpy kidney, pasteurella), dosed for internal parasites.', 'available', 1, NOW() - INTERVAL 2 DAY),
(2, 'GXR-L002', 'meatmaster-slaughter-lambs-gxr-l002', 'Meatmaster slaughter lambs', 3, 2, 'wether', 6, 7, NULL, 18, 18, 44.20, 'per_head', 245000, 25, NULL, CURDATE(), 'Camp 7', 'Finished Meatmaster lambs at target weight.',
 'Finished on irrigated pasture with a lamb finisher supplement. Good conformation and even weights.', 'Withdrawal periods observed — see records.', 'available', 1, NOW() - INTERVAL 1 DAY),
(3, 'GXR-R014', 'dorper-ram-gxr-r014', 'Dorper stud-quality ram', 1, 5, 'ram', 18, 18, 'GX-R014', 1, 1, 92.00, 'per_head', 950000, 30, NULL, CURDATE(), 'Ram camp', 'Strong, well-muscled Dorper ram with excellent conformation.',
 'A **powerful Dorper ram** with good length, depth and muscling. Fertility tested and ready for the breeding season.', 'Fertility tested, vaccinated and dosed.', 'available', 0, NOW() - INTERVAL 3 DAY),
(4, 'GXR-L003', 'samm-ewe-lambs-gxr-l003', 'SA Mutton Merino ewe lambs', 6, 3, 'ewe', 7, 8, NULL, 30, 30, 38.00, 'per_head', 210000, 20, NULL, CURDATE() + INTERVAL 14 DAY, 'Camp 2', 'Replacement ewe lambs — available soon.',
 'Well-grown SAMM ewe lambs selected as flock replacements.', NULL, 'coming_soon', 0, NOW()),
(5, 'GXR-L004', 'van-rooy-ewes-gxr-l004', 'Van Rooy ewes', 7, 4, 'ewe', 24, 36, NULL, 12, 3, 58.50, 'per_head', 215000, 20, NULL, CURDATE(), 'Camp 9', 'Hardy Van Rooy ewes in good condition.',
 'Hardy, adaptable Van Rooy ewes — proven mothers in good condition.', 'Vaccinated and dosed.', 'available', 0, NOW() - INTERVAL 6 DAY);

INSERT INTO lot_health_records (lot_id, record_date, type, product_used, notes, withdrawal_until) VALUES
(1, CURDATE() - INTERVAL 20 DAY, 'vaccination', 'Pulpy kidney + Pasteurella', 'Booster due in 4 weeks', NULL),
(1, CURDATE() - INTERVAL 14 DAY, 'dosing', 'Broad-spectrum dewormer', 'FAMACHA scored', CURDATE() - INTERVAL 7 DAY),
(2, CURDATE() - INTERVAL 45 DAY, 'vaccination', 'Pulpy kidney', NULL, NULL),
(2, CURDATE() - INTERVAL 40 DAY, 'dosing', 'Dewormer', NULL, CURDATE() - INTERVAL 12 DAY),
(3, CURDATE() - INTERVAL 30 DAY, 'inspection', 'Fertility test', 'Passed — good semen quality', NULL),
(3, CURDATE() - INTERVAL 30 DAY, 'vaccination', 'Multi-clostridial', NULL, NULL),
(5, CURDATE() - INTERVAL 25 DAY, 'vaccination', 'Multi-clostridial', NULL, NULL);

INSERT INTO weigh_sessions (lot_id, weighed_on, head_count, avg_weight_kg) VALUES
(1, CURDATE() - INTERVAL 28 DAY, 25, 22.10), (1, CURDATE() - INTERVAL 14 DAY, 25, 25.30), (1, CURDATE() - INTERVAL 2 DAY, 25, 28.40),
(2, CURDATE() - INTERVAL 42 DAY, 18, 36.00), (2, CURDATE() - INTERVAL 21 DAY, 18, 40.40), (2, CURDATE() - INTERVAL 1 DAY, 18, 44.20),
(3, CURDATE() - INTERVAL 10 DAY, 1, 92.00),
(5, CURDATE() - INTERVAL 6 DAY, 12, 58.50);

-- Initial stock movements so the ledger starts balanced
INSERT INTO stock_movements (division_id, variant_id, type, qty_change, balance_after, reference, note)
SELECT 1, id, 'initial', stock_qty, stock_qty, 'DEMO', 'Opening stock (demo)' FROM product_variants;
INSERT INTO stock_movements (division_id, lot_id, type, qty_change, balance_after, reference, note)
SELECT 2, id, 'initial', qty_available, qty_available, 'DEMO', 'Opening stock (demo)' FROM lots;

-- Delivery: activate the example zone with a few towns
UPDATE delivery_zones SET name = 'Local delivery', description = 'Towns within ±40 km of the depot', is_active = 1 WHERE id = 1;
DELETE FROM delivery_zone_towns WHERE zone_id = 1;
INSERT INTO delivery_zones (id, name, description, fee_cents, free_over_cents, min_order_cents, delivery_days, lead_days, is_active, sort_order) VALUES
(2, 'Regional', 'Towns ±40–100 km from the depot', 65000, 600000, 150000, '3', 2, 1, 2);
INSERT INTO delivery_zone_towns (zone_id, town) VALUES
(1, 'Example Town'), (1, 'Riverside'), (1, 'Hillcrest'), (2, 'Far Valley'), (2, 'Mountain View');

INSERT INTO testimonials (division_id, name, location, quote, rating, sort_order) VALUES
(1, 'Johan P.', 'Local farmer', 'Ordered licks on my phone in the evening and collected the next morning. Everything ready, invoice in my email.', 5, 1),
(2, 'Marike V.', 'Feedlot buyer', 'The weaners were exactly as described — even weights and full health records. Collection was quick and organised.', 5, 2),
(NULL, 'Pieter du T.', 'Regular customer', 'Finally a feed store that shows stock and prices online. Bulk pricing makes a real difference.', 5, 3);

INSERT INTO coupons (code, division_id, type, value, min_spend_cents, max_uses, is_active) VALUES
('WELCOME10', 1, 'percent', 10, 100000, 100, 1);
