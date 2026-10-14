let expr = '';
let ans = 0;
let deg = true;
const out = document.getElementById('out');
const res = document.getElementById('res');
const toRad = (x) => (deg ? (x * Math.PI) / 180 : x);
// Whitelisted names the expression may use
const scope = {
    sin: x => Math.sin(toRad(x)),
    cos: x => Math.cos(toRad(x)),
    tan: x => Math.tan(toRad(x)),
    ln: Math.log,
    log: Math.log10,
    sqrt: Math.sqrt,
    PI: Math.PI,
    E: Math.E,
};
function evaluate(s) {
    let e = s
        .replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-').replace(/\^/g, '**')
        .replace(/π/g, ' PI ').replace(/√/g, ' sqrt ').replace(/\be\b/g, ' E ');
    const open = (e.match(/\(/g) ?? []).length;
    const close = (e.match(/\)/g) ?? []).length;
    e += ')'.repeat(Math.max(0, open - close)); // auto-close parentheses
    e = e
        .replace(/(\d|\))\s*(?=PI|E\b|sqrt|sin|cos|tan|ln|log|Ans|\()/g, '$1*') // implicit ×
        .replace(/(\bPI|\bE|\bAns)\s*(?=[\d(])/g, '$1*');
    if (!/^[\d+\-*/().\sA-Za-z]*$/.test(e))
        throw new Error('bad input');
    const names = [...Object.keys(scope), 'Ans'];
    const words = e.match(/[A-Za-z]+/g) ?? [];
    if (words.some(w => !names.includes(w)))
        throw new Error('unknown name');
    const values = [...Object.values(scope), ans];
    const r = new Function(...names, `"use strict"; return (${e});`)(...values);
    if (typeof r !== 'number' || !isFinite(r))
        throw new Error('not finite');
    return r;
}
const fmt = (r) => String(parseFloat(r.toPrecision(12)));
function render(final = false) {
    out.textContent = expr;
    if (!expr) {
        res.textContent = '0';
        return;
    }
    try {
        const r = evaluate(expr);
        res.textContent = fmt(r);
        if (final) {
            ans = r;
            expr = fmt(r);
            out.textContent = '';
        }
    }
    catch {
        if (final)
            res.textContent = 'Error';
    }
}
function press(b) {
    const { v, a } = b.dataset;
    if (v) {
        expr += v;
        render();
    }
    else if (a === 'ac') {
        expr = '';
        render();
    }
    else if (a === 'del') {
        expr = expr.replace(/(sin\(|cos\(|tan\(|ln\(|log\(|√\(|Ans|.)$/, '');
        render();
    }
    else if (a === 'eq')
        render(true);
    else if (a === 'deg') {
        deg = !deg;
        b.textContent = deg ? 'DEG' : 'RAD';
        render();
    }
}
document.getElementById('keys').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (b)
        press(b);
});
// Keyboard support
document.addEventListener('keydown', (e) => {
    const k = e.key;
    if (/^[0-9.()+^]$/.test(k))
        expr += k;
    else if (k === '-')
        expr += '−';
    else if (k === '*')
        expr += '×';
    else if (k === '/') {
        e.preventDefault();
        expr += '÷';
    }
    else if (k === 'Enter' || k === '=')
        return render(true);
    else if (k === 'Backspace')
        expr = expr.slice(0, -1);
    else if (k === 'Escape')
        expr = '';
    else
        return;
    render();
});
if ('serviceWorker' in navigator)
    navigator.serviceWorker.register('sw.js');
export {};
