// Normalize a pinned PSGC mirror without executing any downloaded code.
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = path.resolve(process.argv[2] || path.join(root, 'storage/psgc-source/barangay-flat.json'));
const raw = fs.readFileSync(source);
const sourceHash = createHash('sha256').update(raw).digest('hex');
if (sourceHash !== '440755bcb8a119e1314104352c280b2096d58007baaeb487a589a1f54b445bad') {
    throw new Error('Source differs from the pinned October 2026 snapshot. Review the data and update provenance before importing.');
}
const records = JSON.parse(raw);
const byCode = new Map();
for (const record of records) {
    if (!/^\d{10}$/.test(record.psgc_id) || byCode.has(record.psgc_id)) throw new Error('Invalid or duplicate PSGC code');
    byCode.set(record.psgc_id, record);
}
function ancestors(record) {
    const result = [];
    const visited = new Set();
    for (let parent = record.parent_psgc_id; parent !== '0000000000';) {
        if (visited.has(parent) || !byCode.has(parent)) throw new Error('Invalid PSGC ancestry: ' + record.psgc_id);
        visited.add(parent);
        const row = byCode.get(parent); result.push(row); parent = row.parent_psgc_id;
    }
    return result;
}
const cityTypes = new Set(['highly_urbanized_city','independent_component_city','component_city','municipality']);
// The mirror types the province-level Isabela grouping as a city, above the
// actual City of Isabela record. Grouping nodes must not become empty city choices.
const cityGroupingCodes = new Set(records.filter(row => cityTypes.has(row.type) && cityTypes.has(byCode.get(row.parent_psgc_id)?.type)).map(row => row.parent_psgc_id));
const areas = records.filter(row => ['region','province'].includes(row.type)).map(row => {
    const region = row.type === 'region' ? row : ancestors(row).find(parent => parent.type === 'region');
    return { code:row.psgc_id, name:row.psgc_id === '1300000000' ? 'Metro Manila (NCR)' : row.name,
        kind:row.type, region_code:region.psgc_id, region_name:region.name };
});
const cities = {};
const citiesByArea = Object.fromEntries(areas.map(area => [area.code, []]));
for (const row of records.filter(row => cityTypes.has(row.type) && !cityGroupingCodes.has(row.psgc_id))) {
    const parents = ancestors(row);
    const region = parents.find(parent => parent.type === 'region');
    const province = parents.find(parent => parent.type === 'province');
    const areaCodes = parents.filter(parent => ['region','province'].includes(parent.type)).map(parent => parent.psgc_id);
    cities[row.psgc_id] = { code:row.psgc_id, name:row.name, region_code:region.psgc_id,
        province_name:province?.name || null, area_codes:areaCodes, barangays:[] };
    for (const code of areaCodes) citiesByArea[code].push(row.psgc_id);
}
let barangayCount = 0;
for (const row of records.filter(row => row.type === 'barangay')) {
    const parents = ancestors(row);
    const city = parents.find(parent => cityTypes.has(parent.type));
    if (!city || !cities[city.psgc_id]) throw new Error('Barangay without city/municipality: ' + row.psgc_id);
    const district = parents.find(parent => parent.type === 'submunicipality');
    cities[city.psgc_id].barangays.push({ code:row.psgc_id, name:row.name, ...(district ? { district:district.name } : {}) });
    barangayCount++;
}
const alphabetically = (a,b) => a.name.localeCompare(b.name, 'en-PH', { numeric:true });
areas.sort(alphabetically);
for (const city of Object.values(cities)) city.barangays.sort(alphabetically);
for (const codes of Object.values(citiesByArea)) codes.sort((a,b) => alphabetically(cities[a],cities[b]));
const counts = { regions:areas.filter(area=>area.kind==='region').length, provinces:areas.filter(area=>area.kind==='province').length,
    cities_municipalities:Object.keys(cities).length, barangays:barangayCount };
if (counts.regions !== 18 || counts.provinces !== 82 || counts.cities_municipalities !== 1642 || counts.barangays !== 42010) throw new Error('Incomplete snapshot: ' + JSON.stringify(counts));
const metadata = { version:'2026-10-12', retrieved_on:'2026-10-27', publisher:'Philippine Statistics Authority (PSGC)',
    mirror:'https://github.com/bendlikeabamboo/barangay', mirror_commit:'16ce5315dad13cf3df80d43edc15053646ae61a5',
    source_sha256:sourceHash, counts,
    normalization:['Flattened Manila submunicipalities into their city barangay list.', 'Excluded grouping node 0990100000 from city choices; retained City of Isabela 0990101000 and all its barangays.'] };
const target = path.join(root, 'database/reference'); fs.mkdirSync(target, { recursive:true });
fs.writeFileSync(path.join(target,'ph-locations.json'), JSON.stringify({ metadata, areas, cities, cities_by_area:citiesByArea }) + '\n');
fs.copyFileSync(path.join(path.dirname(source),'LICENSE'),path.join(target,'PSGC-MIRROR-LICENSE.txt'));
console.log('Prepared Philippine address directory: ' + JSON.stringify(counts));
