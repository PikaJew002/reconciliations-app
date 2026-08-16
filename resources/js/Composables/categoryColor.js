export function randomCategoryColor() {
    return hslToHex(Math.floor(Math.random() * 360), 0.58, 0.45);
}

export function stripColorHash(value) {
    return String(value || '')
        .trim()
        .replace(/^#/, '');
}

function hslToHex(hue, saturation, lightness) {
    let h = (((hue % 360) + 360) % 360) / 360;
    let s = Math.max(0, Math.min(1, saturation));
    let l = Math.max(0, Math.min(1, lightness));
    let chroma = (1 - Math.abs(2 * l - 1)) * s;
    let x = chroma * (1 - Math.abs(((h * 6) % 2) - 1));
    let m = l - chroma / 2;
    let r = 0;
    let g = 0;
    let b = 0;
    let h6 = h * 6;

    if (h6 < 1) {
        [r, g, b] = [chroma, x, 0];
    } else if (h6 < 2) {
        [r, g, b] = [x, chroma, 0];
    } else if (h6 < 3) {
        [r, g, b] = [0, chroma, x];
    } else if (h6 < 4) {
        [r, g, b] = [0, x, chroma];
    } else if (h6 < 5) {
        [r, g, b] = [x, 0, chroma];
    } else {
        [r, g, b] = [chroma, 0, x];
    }

    let toHex = (value) =>
        Math.round((value + m) * 255)
            .toString(16)
            .padStart(2, '0');

    return `#${toHex(r)}${toHex(g)}${toHex(b)}`.toUpperCase();
}
