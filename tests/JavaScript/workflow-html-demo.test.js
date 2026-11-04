import assert from 'node:assert/strict';
import test from 'node:test';
import { DEFAULT_DEMO_CHECKS, checkDemoHtml, demoRepairSuffix, extractHtml } from '../../bin/workflow/html-demo.mjs';

const workingScript = `
    const canvas = document.getElementById('game');
    canvas.addEventListener('pointerdown', (event) => event.preventDefault());
    window.addEventListener('resize', () => {});
    function frame() { requestAnimationFrame(frame); }
    frame();`;

function demoDocument({ head = '', body = '', script = workingScript } = {}) {
    return `<!DOCTYPE html>
<html lang="tr">
<head><meta charset="utf-8"><title>Demo</title>${head}</head>
<body>
<canvas id="game"></canvas>
${body}
<script>${script}
</script>
</body>
</html>`;
}

function codes(issues) {
    return issues.map((issue) => issue.code);
}

test('takes the first html block, else a fenced block with a document, else the raw document', () => {
    assert.deepEqual(
        extractHtml('Plan:\n```js\nlet a;\n```\n```HTML\n<!doctype html><html><body>1</body></html>\n```\n```html\n<html>2</html>\n```'),
        { html: '<!doctype html><html><body>1</body></html>', truncated: false },
    );
    assert.deepEqual(
        extractHtml('```css\nbody {}\n```\n```\n<html><body>2</body></html>\n```'),
        { html: '<html><body>2</body></html>', truncated: false },
    );
    assert.deepEqual(
        extractHtml('Here you go: <!DOCTYPE html><html><body>3</body></html><!-- </html> --> Enjoy!'),
        { html: '<!DOCTYPE html><html><body>3</body></html><!-- </html>', truncated: false },
    );
    assert.deepEqual(extractHtml('Intro <html lang="en"><body>4'), { html: '<html lang="en"><body>4', truncated: true });
    assert.deepEqual(extractHtml('```html\n<!doctype html><html><body><script>let a = 1'), {
        html: '<!doctype html><html><body><script>let a = 1', truncated: true,
    });
    assert.deepEqual(extractHtml('I cannot write that demo.'), { html: null, truncated: false });
    assert.deepEqual(extractHtml('```html\n\n```'), { html: null, truncated: false });
    assert.deepEqual(extractHtml(null), { html: null, truncated: false });
});

test('passes a complete offline demo without critical issues or warnings', () => {
    assert.deepEqual(checkDemoHtml(demoDocument()), { critical: [], warnings: [] });
    assert.deepEqual(checkDemoHtml(demoDocument(), { requireListeners: ['pointerdown'] }), { critical: [], warnings: [] });
    assert.deepEqual(DEFAULT_DEMO_CHECKS.requireListeners, ['pointerdown', 'touchstart', 'mousedown']);
});

test('reports a missing or cut off document as critical', () => {
    const missing = checkDemoHtml('<body><p>No document</p></body>');
    assert.deepEqual(missing.critical[0], {
        code: 'missing-html',
        message: 'Çıktı kesilmiş: <html> veya </html> etiketi yok.',
        detail: 'The HTML document is incomplete: the <html> or </html> tag is missing.',
    });

    const cut = demoDocument().replace('</html>', '');
    assert.equal(checkDemoHtml(cut).critical[0].code, 'missing-html');
    assert.deepEqual(codes(checkDemoHtml(null).critical), ['missing-html']);
    assert.deepEqual(codes(checkDemoHtml('').critical), ['missing-html']);
});

test('compiles classic inline scripts without running them and reports syntax errors by line', () => {
    globalThis.benchforgeDemoRan = false;
    const harmless = demoDocument({ body: '<script>globalThis.benchforgeDemoRan = true; throw new Error("ran");</script>' });
    assert.deepEqual(checkDemoHtml(harmless).critical, []);
    assert.equal(globalThis.benchforgeDemoRan, false);
    delete globalThis.benchforgeDemoRan;

    const broken = demoDocument({ body: '<script type="text/javascript">\nconst ok = 1;\nlet x = ;\n</script>' });
    const issues = checkDemoHtml(broken).critical;
    assert.deepEqual(codes(issues), ['script-syntax']);
    assert.equal(issues[0].message, 'Satır içi script sözdizimi hatası: Unexpected token \';\' (satır 8)');
    assert.equal(issues[0].detail, 'Inline script 1 has a syntax error at line 8 of the document: Unexpected token \';\'');
});

test('skips module, data and shader scripts in the syntax check and warns about modules', () => {
    const html = demoDocument({
        body: [
            '<script type="module">import { x } from "./local.js"; await x();</script>',
            '<script type="application/json">{ not: valid js }</script>',
            '<script type="x-shader/x-fragment">void main() { gl_FragColor = vec4(1.0); }</script>',
            '<!-- <script>let broken = ;</script> -->',
        ].join('\n'),
    });
    const result = checkDemoHtml(html);

    assert.deepEqual(result.critical, []);
    assert.deepEqual(codes(result.warnings), ['module-script']);
    assert.equal(result.warnings[0].message, 'type="module" script var; sözdizimi denetlenmedi.');
});

test('flags every kind of external network access', () => {
    const cases = [
        { head: '<link rel="stylesheet" href="https://cdn.example.com/a.css">' },
        { head: '<script src="//cdn.example.com/lib.js"></script>' },
        { body: '<img src=http://example.com/a.png alt="">' },
        { body: '<img srcset="small.png 1x, https://example.com/big.png 2x" alt="">' },
        { body: '<a href="https://example.com">site</a>' },
        { body: '<form action="https&#58;//example.com/send"></form>' },
        { head: '<style>@import "https://fonts.example.com/a.css";</style>' },
        { head: '<style>body { background: url( \'https://example.com/bg.png\' ); }</style>' },
        { body: '<div style="background-image:url(//example.com/x.png)"></div>' },
        { script: `${workingScript}\nfetch('/scores.json');` },
        { script: `${workingScript}\nconst request = new XMLHttpRequest();` },
        { script: `${workingScript}\nconst socket = new WebSocket('wss://example.com');` },
        { script: `${workingScript}\nimportScripts('worker.js');` },
        { script: `${workingScript}\nconst events = new EventSource('/stream');` },
        { script: `${workingScript}\nnavigator.sendBeacon('/log', 'x');` },
        { script: `${workingScript}\nconst peer = new RTCPeerConnection({ iceServers: [{ urls: 'stun:example.com:3478' }] });` },
        { script: `${workingScript}\nlocation.href = 'ht' + 'tps://example.com/n?d=' + navigator.userAgent;` },
        { script: `${workingScript}\nwindow.location = '/next';` },
        { script: `${workingScript}\nlocation.assign('/next');` },
        { script: `${workingScript}\nconst image = new Image(); image.src = "https://example.com/sprite.png";` },
        { script: `${workingScript}\nconst music = new Audio('https://example.com/song.mp3');` },
        { script: `${workingScript}\nimport('https://esm.sh/lib');` },
    ];

    for (const parts of cases) {
        const issues = checkDemoHtml(demoDocument(parts)).critical;
        assert.deepEqual(codes(issues), ['external-network'], JSON.stringify(parts));
        assert.match(issues[0].message, /^Dış ağ erişimi: /);
        assert.match(issues[0].detail, /^External network access is not allowed: /);
    }
});

test('does not treat local, inline or commented URLs as network access', () => {
    const html = demoDocument({
        head: '<link rel="icon" href="data:,"><style>.a { background: url(data:image/png;base64,AAAA); } .b { background: url("sprite.png"); }</style>',
        body: [
            '<a href="#top">Başa dön</a>',
            '<a href="javascript:void(0)">Oyna</a>',
            '<img src="blob:https://example.com/1234" alt="">',
            '<img src="images/hero.png" srcset="hero.png 1x, hero@2x.png 2x" alt="">',
            '<img src="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\'></svg>" alt="">',
            '<svg xmlns="http://www.w3.org/2000/svg"><use href="#ship"/></svg>',
            '<!-- <script src="https://cdn.example.com/x.js"></script> fetch("x") -->',
            '<p>Visit https://example.com for more.</p>',
        ].join('\n'),
        script: `${workingScript}
            // fetch('https://example.com') would need the network, so it is not used.
            /* new WebSocket('wss://example.com') */
            const ns = 'http://www.w3.org/2000/svg';
            const link = new URL('https://example.com/share');
            const sprite = URL.createObjectURL(new Blob(['x']));
            function restart() { location.reload(); }
            function hardRestart() { location.href = location.href; }
            const here = location.href === 'about:srcdoc';`,
    });

    assert.deepEqual(checkDemoHtml(html), { critical: [], warnings: [] });
});

test('lists each external address once and can allow the network', () => {
    const html = demoDocument({ head: '<style>@import url("https://fonts.example.com/a.css");</style>' });

    assert.equal(checkDemoHtml(html).critical.length, 1);
    assert.deepEqual(checkDemoHtml(html, { forbidNetwork: false }).critical, []);
});

test('requires one of the input listeners in any common form', () => {
    const withoutInput = `
        window.addEventListener('resize', () => {});
        function frame() { requestAnimationFrame(frame); }
        frame();`;
    const missing = checkDemoHtml(demoDocument({ script: withoutInput })).critical;

    assert.deepEqual(missing, [{
        code: 'missing-input-listener',
        message: 'Dokunma/fare dinleyicisi yok (pointerdown, touchstart veya mousedown).',
        detail: 'No input listener was found: listen to pointerdown, touchstart or mousedown with addEventListener.',
    }]);
    assert.deepEqual(checkDemoHtml(demoDocument({ script: `${withoutInput}\n// canvas.addEventListener('mousedown', start);` })).critical, missing);

    for (const script of [
        `${withoutInput}\ndocument.addEventListener("touchstart", start, { passive: false });`,
        `${withoutInput}\ncanvas.onmousedown = start;`,
        `${withoutInput}\n['pointerdown', 'pointermove'].forEach((name) => canvas.addEventListener(name, start));`,
    ]) {
        assert.deepEqual(checkDemoHtml(demoDocument({ script })).critical, [], script);
    }
    assert.deepEqual(checkDemoHtml(demoDocument({ script: withoutInput, body: '<button onpointerdown="start()">Başla</button>' })).critical, []);

    const keyboard = checkDemoHtml(demoDocument(), { requireListeners: ['keydown', 'keyup'] }).critical;
    assert.equal(keyboard[0].message, 'Gerekli olay dinleyicisi yok (keydown veya keyup).');
    assert.deepEqual(checkDemoHtml(demoDocument({ script: withoutInput }), { requireListeners: [] }).critical, []);
});

test('warns about resize, animation loop, page visibility and size', () => {
    const bare = checkDemoHtml(demoDocument({ script: 'document.body.addEventListener("pointerdown", () => {});' }));
    assert.deepEqual(bare.critical, []);
    assert.deepEqual(codes(bare.warnings), ['missing-resize', 'missing-raf']);
    assert.equal(bare.warnings[0].message, 'Ekran boyutu değişimi (resize) dinlenmiyor.');
    assert.equal(bare.warnings[1].message, 'requestAnimationFrame kullanılmıyor.');

    const observer = `${workingScript.replace("window.addEventListener('resize', () => {});", '')}\nnew ResizeObserver(() => {}).observe(canvas);`;
    assert.deepEqual(checkDemoHtml(demoDocument({ script: observer })).warnings, []);

    for (const usage of ["document.addEventListener('visibilitychange', pause);", 'if (document.hidden) pause();', "if (document.visibilityState === 'hidden') pause();"]) {
        assert.deepEqual(codes(checkDemoHtml(demoDocument({ script: `${workingScript}\n${usage}` })).warnings), ['visibility-api'], usage);
    }

    const large = demoDocument({ body: `<p>${'ş'.repeat(3000)}</p>` });
    const sized = checkDemoHtml(large, { maxBytes: 4096 });
    assert.deepEqual(codes(sized.warnings), ['too-large']);
    assert.match(sized.warnings[0].message, /^Dosya \d+ KB; önerilen üst sınır 4 KB\.$/);
    assert.deepEqual(checkDemoHtml(large).warnings, []);
});

test('builds the repair prompt from the issue details and the previous HTML', () => {
    const problems = [
        { code: 'script-syntax', message: 'Türkçe', detail: 'Inline script 1 has a syntax error at line 3 of the document: Unexpected token' },
        'Plain problem text',
    ];

    assert.equal(
        demoRepairSuffix(problems, '<html></html>', false),
        '\n\nYOUR PREVIOUS ANSWER HAD THESE PROBLEMS:\n'
            + '- Inline script 1 has a syntax error at line 3 of the document: Unexpected token\n'
            + '- Plain problem text\n'
            + 'Previous HTML:\n```html\n<html></html>\n```\n'
            + 'Return the complete corrected HTML document in a single ```html code block.',
    );
    assert.equal(
        demoRepairSuffix([{ code: 'missing-html', message: 'Çıktı kesilmiş.' }], '<html><body>', true),
        '\n\nYOUR PREVIOUS ANSWER HAD THESE PROBLEMS:\n'
            + '- Çıktı kesilmiş.\n'
            + 'Your answer was cut off. Write a smaller version that fits.\n'
            + 'Return the complete corrected HTML document in a single ```html code block.',
    );

    const large = `<html>${'x'.repeat(150 * 1024)}</html>`;
    assert.ok(!demoRepairSuffix(problems, large, false).includes('Previous HTML:'));
    assert.ok(demoRepairSuffix(problems, `<html>${'x'.repeat(1000)}</html>`, false).includes('Previous HTML:'));
    assert.ok(!demoRepairSuffix(problems, null, false).includes('Previous HTML:'));
});
