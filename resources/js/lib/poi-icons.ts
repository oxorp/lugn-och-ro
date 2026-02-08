// Pre-extracted Lucide icon node data for POI map markers.
// Format: [elementType, attributes][] — matches Lucide's __iconNode structure.
// All icons use viewBox="0 0 24 24", stroke-based rendering.

type IconNode = [string, Record<string, string>][];

// Map from Lucide icon name → SVG element nodes
const ICON_NODES: Record<string, IconNode> = {
    factory: [
        ['path', { d: 'M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z' }],
        ['path', { d: 'M17 18h1' }],
        ['path', { d: 'M12 18h1' }],
        ['path', { d: 'M7 18h1' }],
    ],
    droplets: [
        ['path', { d: 'M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z' }],
        ['path', { d: 'M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97' }],
    ],
    'trash-2': [
        ['path', { d: 'M3 6h18' }],
        ['path', { d: 'M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6' }],
        ['path', { d: 'M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2' }],
        ['line', { x1: '10', x2: '10', y1: '11', y2: '17' }],
        ['line', { x1: '14', x2: '14', y1: '11', y2: '17' }],
    ],
    mountain: [
        ['path', { d: 'm8 3 4 8 5-5 5 15H2L8 3z' }],
    ],
    lock: [
        ['rect', { width: '18', height: '11', x: '3', y: '11', rx: '2', ry: '2' }],
        ['path', { d: 'M7 11V7a5 5 0 0 1 10 0v4' }],
    ],
    plane: [
        ['path', { d: 'M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z' }],
    ],
    crosshair: [
        ['circle', { cx: '12', cy: '12', r: '10' }],
        ['line', { x1: '22', x2: '18', y1: '12', y2: '12' }],
        ['line', { x1: '6', x2: '2', y1: '12', y2: '12' }],
        ['line', { x1: '12', x2: '12', y1: '6', y2: '2' }],
        ['line', { x1: '12', x2: '12', y1: '22', y2: '18' }],
    ],
    wind: [
        ['path', { d: 'M12.8 19.6A2 2 0 1 0 14 16H2' }],
        ['path', { d: 'M17.5 8a2.5 2.5 0 1 1 2 4H2' }],
        ['path', { d: 'M9.8 4.4A2 2 0 1 1 11 8H2' }],
    ],
    flame: [
        ['path', { d: 'M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z' }],
    ],
    'shopping-cart': [
        ['circle', { cx: '8', cy: '21', r: '1' }],
        ['circle', { cx: '19', cy: '21', r: '1' }],
        ['path', { d: 'M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12' }],
    ],
    cross: [
        ['path', { d: 'M4 9a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h4a1 1 0 0 1 1 1v4a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-4a1 1 0 0 1 1-1h4a2 2 0 0 0 2-2v-2a2 2 0 0 0-2-2h-4a1 1 0 0 1-1-1V4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4a1 1 0 0 1-1 1z' }],
    ],
    coffee: [
        ['path', { d: 'M10 2v2' }],
        ['path', { d: 'M14 2v2' }],
        ['path', { d: 'M16 8a1 1 0 0 1 1 1v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1h14a4 4 0 1 1 0 8h-1' }],
        ['path', { d: 'M6 2v2' }],
    ],
    dumbbell: [
        ['path', { d: 'M14.4 14.4 9.6 9.6' }],
        ['path', { d: 'M18.657 21.485a2 2 0 1 1-2.829-2.828l-1.767 1.768a2 2 0 1 1-2.829-2.829l6.364-6.364a2 2 0 1 1 2.829 2.829l-1.768 1.767a2 2 0 1 1 2.828 2.829z' }],
        ['path', { d: 'm21.5 21.5-1.4-1.4' }],
        ['path', { d: 'M3.9 3.9 2.5 2.5' }],
        ['path', { d: 'M6.404 12.768a2 2 0 1 1-2.829-2.829l1.768-1.767a2 2 0 1 1-2.828-2.829l2.828-2.828a2 2 0 1 1 2.829 2.828l1.767-1.768a2 2 0 1 1 2.829 2.829z' }],
    ],
    bus: [
        ['path', { d: 'M8 6v6' }],
        ['path', { d: 'M15 6v6' }],
        ['path', { d: 'M2 12h19.6' }],
        ['path', { d: 'M18 18h3s.5-1.7.8-2.8c.1-.4.2-.8.2-1.2 0-.4-.1-.8-.2-1.2l-1.4-5C20.1 6.8 19.1 6 18 6H4a2 2 0 0 0-2 2v10h3' }],
        ['circle', { cx: '7', cy: '18', r: '2' }],
        ['path', { d: 'M9 18h5' }],
        ['circle', { cx: '16', cy: '18', r: '2' }],
    ],
    pill: [
        ['path', { d: 'm10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z' }],
        ['path', { d: 'm8.5 8.5 7 7' }],
    ],
    'book-open': [
        ['path', { d: 'M12 7v14' }],
        ['path', { d: 'M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z' }],
    ],
    'tree-pine': [
        ['path', { d: 'm17 14 3 3.3a1 1 0 0 1-.7 1.7H4.7a1 1 0 0 1-.7-1.7L7 14h-.3a1 1 0 0 1-.7-1.7L9 9h-.2A1 1 0 0 1 8 7.3L12 3l4 4.3a1 1 0 0 1-.8 1.7H15l3 3.3a1 1 0 0 1-.7 1.7H17Z' }],
        ['path', { d: 'M12 22v-3' }],
    ],
    trees: [
        ['path', { d: 'M10 10v.2A3 3 0 0 1 8.9 16H5a3 3 0 0 1-1-5.8V10a3 3 0 0 1 6 0Z' }],
        ['path', { d: 'M7 16v6' }],
        ['path', { d: 'M13 19v3' }],
        ['path', { d: 'M12 19h8.3a1 1 0 0 0 .7-1.7L18 14h.3a1 1 0 0 0 .7-1.7L16 9h.2a1 1 0 0 0 .8-1.7L13 3l-1.4 1.5' }],
    ],
    sailboat: [
        ['path', { d: 'M22 18H2a4 4 0 0 0 4 4h12a4 4 0 0 0 4-4Z' }],
        ['path', { d: 'M21 14 10 2 3 14h18Z' }],
        ['path', { d: 'M10 2v16' }],
    ],
    waves: [
        ['path', { d: 'M2 6c.6.5 1.2 1 2.5 1C7 7 7 5 9.5 5c2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1' }],
        ['path', { d: 'M2 12c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1' }],
        ['path', { d: 'M2 18c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1' }],
    ],
    landmark: [
        ['line', { x1: '3', x2: '21', y1: '22', y2: '22' }],
        ['line', { x1: '6', x2: '6', y1: '18', y2: '11' }],
        ['line', { x1: '10', x2: '10', y1: '18', y2: '11' }],
        ['line', { x1: '14', x2: '14', y1: '18', y2: '11' }],
        ['line', { x1: '18', x2: '18', y1: '18', y2: '11' }],
        ['polygon', { points: '12 2 20 7 4 7' }],
    ],
    book: [
        ['path', { d: 'M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20' }],
    ],
    'dice-3': [
        ['rect', { width: '18', height: '18', x: '3', y: '3', rx: '2', ry: '2' }],
        ['path', { d: 'M16 8h.01' }],
        ['path', { d: 'M12 12h.01' }],
        ['path', { d: 'M8 16h.01' }],
    ],
    'badge-dollar-sign': [
        ['path', { d: 'M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z' }],
        ['path', { d: 'M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8' }],
        ['path', { d: 'M12 18V6' }],
    ],
    utensils: [
        ['path', { d: 'M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2' }],
        ['path', { d: 'M7 2v20' }],
        ['path', { d: 'M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7' }],
    ],
    music: [
        ['path', { d: 'M9 18V5l12-2v13' }],
        ['circle', { cx: '6', cy: '18', r: '3' }],
        ['circle', { cx: '18', cy: '16', r: '3' }],
    ],
    store: [
        ['path', { d: 'm2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7' }],
        ['path', { d: 'M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8' }],
        ['path', { d: 'M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4' }],
        ['path', { d: 'M2 7h20' }],
        ['path', { d: 'M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7' }],
    ],
    recycle: [
        ['path', { d: 'M7 19H4.815a1.83 1.83 0 0 1-1.57-.881 1.785 1.785 0 0 1-.004-1.784L7.196 9.5' }],
        ['path', { d: 'M11 19h8.203a1.83 1.83 0 0 0 1.556-.89 1.784 1.784 0 0 0 0-1.775l-1.226-2.12' }],
        ['path', { d: 'm14 16-3 3 3 3' }],
        ['path', { d: 'M8.293 13.596 7.196 9.5 3.1 10.598' }],
        ['path', { d: 'm9.344 5.811 1.093-1.892A1.83 1.83 0 0 1 11.985 3a1.784 1.784 0 0 1 1.546.888l3.943 6.843' }],
        ['path', { d: 'm13.378 9.633 4.096 1.098 1.097-4.096' }],
    ],
    bed: [
        ['path', { d: 'M2 4v16' }],
        ['path', { d: 'M2 8h18a2 2 0 0 1 2 2v10' }],
        ['path', { d: 'M2 17h20' }],
        ['path', { d: 'M6 8v9' }],
    ],
    'graduation-cap': [
        ['path', { d: 'M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z' }],
        ['path', { d: 'M22 10v6' }],
        ['path', { d: 'M6 12.5V16a6 3 0 0 0 12 0v-3.5' }],
    ],
};

// SVG data URL cache — keyed by "icon-color-size"
const svgCache = new Map<string, string>();

/**
 * Convert Lucide icon node data to an SVG element string.
 */
function iconNodesToSvgElements(nodes: IconNode): string {
    return nodes
        .map(([tag, attrs]) => {
            const attrStr = Object.entries(attrs)
                .filter(([k]) => k !== 'key')
                .map(([k, v]) => `${k}="${v}"`)
                .join(' ');
            // Self-closing tags
            return `<${tag} ${attrStr}/>`;
        })
        .join('');
}

/**
 * Create SVG marker with background pill, icon, and pointer stem.
 */
export function createPoiMarkerSvg(
    iconName: string,
    bgColor: string,
    size: number,
): string | null {
    const nodes = ICON_NODES[iconName];
    if (!nodes) return null;

    const iconElements = iconNodesToSvgElements(nodes);
    const stemH = Math.round(size * 0.22);
    const totalH = size + stemH;
    const r = Math.round(size * 0.17);
    const pad = Math.round(size * 0.15);
    const iconSize = size - pad * 2;

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${totalH}" viewBox="0 0 ${size} ${totalH}">
<defs><filter id="s" x="-20%" y="-10%" width="140%" height="130%">
<feDropShadow dx="0" dy="1" stdDeviation="1.2" flood-opacity="0.3"/>
</filter></defs>
<rect x="1" y="1" width="${size - 2}" height="${size - 2}" rx="${r}" fill="${bgColor}" filter="url(#s)"/>
<polygon points="${size / 2 - 3},${size - 1} ${size / 2},${totalH - 1} ${size / 2 + 3},${size - 1}" fill="${bgColor}"/>
<svg x="${pad}" y="${pad}" width="${iconSize}" height="${iconSize}" viewBox="0 0 24 24"
fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
${iconElements}
</svg></svg>`;
}

/**
 * Get a data URL for an SVG POI marker. Cached.
 */
export function getPoiMarkerDataUrl(
    iconName: string,
    bgColor: string,
    size: number,
): string | null {
    const key = `${iconName}-${bgColor}-${size}`;
    const cached = svgCache.get(key);
    if (cached) return cached;

    const svg = createPoiMarkerSvg(iconName, bgColor, size);
    if (!svg) return null;

    const url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    svgCache.set(key, url);
    return url;
}

/**
 * Check if we have icon data for a given Lucide icon name.
 */
export function hasIcon(iconName: string): boolean {
    return iconName in ICON_NODES;
}
