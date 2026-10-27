/**
 * Utilidades pequeñas sin dependencias compartidas por el visor y el editor.
 */

export function qs(selector, root = document) {
    return root.querySelector(selector);
}

export function qsa(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
}

export function clear(node) {
    while (node.firstChild) {
        node.removeChild(node.firstChild);
    }
}

/**
 * Crea un elemento HTML con atributos y hijos.
 */
export function el(tag, attributes = {}, children = []) {
    const element = document.createElement(tag);

    for (const [key, value] of Object.entries(attributes)) {
        if (value === undefined || value === null || value === false) {
            continue;
        }

        if (key === 'className') {
            element.className = value;
            continue;
        }

        if (key === 'dataset') {
            for (const [dataKey, dataValue] of Object.entries(value)) {
                element.dataset[dataKey] = dataValue;
            }
            continue;
        }

        if (key === 'text') {
            element.textContent = value;
            continue;
        }

        if (key === 'html') {
            element.innerHTML = value;
            continue;
        }

        if (key.startsWith('on') && typeof value === 'function') {
            element.addEventListener(key.slice(2).toLowerCase(), value);
            continue;
        }

        if (value === true) {
            element.setAttribute(key, '');
            continue;
        }

        element.setAttribute(key, String(value));
    }

    append(element, children);

    return element;
}

/**
 * Crea un elemento SVG con atributos y hijos.
 */
export function svg(tag, attributes = {}, children = []) {
    const element = document.createElementNS('http://www.w3.org/2000/svg', tag);

    for (const [key, value] of Object.entries(attributes)) {
        if (value === undefined || value === null || value === false) {
            continue;
        }

        if (key === 'className') {
            element.setAttribute('class', value);
            continue;
        }

        if (key === 'text') {
            element.textContent = value;
            continue;
        }

        if (key.startsWith('on') && typeof value === 'function') {
            element.addEventListener(key.slice(2).toLowerCase(), value);
            continue;
        }

        element.setAttribute(key, String(value));
    }

    append(element, children);

    return element;
}

function append(parent, children) {
    const items = Array.isArray(children) ? children : [children];

    for (const child of items) {
        if (child === null || child === undefined || child === false) {
            continue;
        }

        if (typeof child === 'string' || typeof child === 'number') {
            parent.appendChild(document.createTextNode(String(child)));
            continue;
        }

        parent.appendChild(child);
    }
}

export function clone(value) {
    if (typeof structuredClone === 'function') {
        return structuredClone(value);
    }

    return JSON.parse(JSON.stringify(value));
}

export function debounce(callback, wait = 150) {
    let timer = null;

    const debounced = (...args) => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => callback(...args), wait);
    };

    debounced.cancel = () => window.clearTimeout(timer);

    return debounced;
}

export function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, value));
}

export function singularize(word) {
    if (word.endsWith('ies') && word.length > 3) {
        return `${word.slice(0, -3)}y`;
    }

    if (word.endsWith('sses') || word.endsWith('shes') || word.endsWith('ches') || word.endsWith('xes')) {
        return word.slice(0, -2);
    }

    if (word.endsWith('s') && !word.endsWith('ss') && word.length > 1) {
        return word.slice(0, -1);
    }

    return word;
}

export function fkName(table) {
    return `${singularize(table)}_id`;
}

export function pivotName(first, second) {
    return [first, second].sort().join('_');
}

export function studly(value) {
    return value
        .split(/[_\-\s]+/)
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join('');
}

export function isSnakeCase(value) {
    return /^[a-z][a-z0-9_]*$/.test(value);
}

export function isSafeName(value) {
    return /^[A-Za-z_][A-Za-z0-9_]*$/.test(value);
}

export const LIFECYCLE_FIELDS = [
    'created_at',
    'updated_at',
    'deleted_at',
];

export function isLifecycleField(name) {
    return LIFECYCLE_FIELDS.includes(name);
}

export function formatCount(value) {
    return new Intl.NumberFormat('en-US').format(value);
}

export function formatDefault(value, hasDefault) {
    if (!hasDefault) {
        return '';
    }

    if (value === null) {
        return 'null';
    }

    if (typeof value === 'string') {
        return `'${value}'`;
    }

    return String(value);
}

/**
 * Interpreta el input de default del editor.
 */
export function parseDefaultInput(raw) {
    const value = raw.trim();

    if (value === '') {
        return { present: false, value: null };
    }

    if (value === 'null') {
        return { present: true, value: null };
    }

    if (value === 'true') {
        return { present: true, value: true };
    }

    if (value === 'false') {
        return { present: true, value: false };
    }

    if (/^-?\d+(\.\d+)?$/.test(value)) {
        return { present: true, value: Number(value) };
    }

    if (
        (value.startsWith("'") && value.endsWith("'") && value.length >= 2)
        || (value.startsWith('"') && value.endsWith('"') && value.length >= 2)
    ) {
        return { present: true, value: value.slice(1, -1) };
    }

    return { present: true, value };
}

export function download(filename, contents, type = 'application/octet-stream') {
    const blob = new Blob([contents], { type });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    textarea.remove();
}

export function slugify(value) {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export function tableHue(name) {
    let hash = 0;

    for (let index = 0; index < name.length; index += 1) {
        hash = (hash * 31 + name.charCodeAt(index)) % 360;
    }

    return hash;
}

export function isEditableTarget(target) {
    if (!(target instanceof Element)) {
        return false;
    }

    return Boolean(target.closest('input, textarea, select, [contenteditable="true"]'));
}

export function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
