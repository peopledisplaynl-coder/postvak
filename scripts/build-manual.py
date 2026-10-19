#!/usr/bin/env python3
"""
Build the user manual as Word (.docx) and PDF, in Dutch and English:
  docs/HANDLEIDING.md → docs/manual/Handleiding-Postvak.docx / .pdf
  docs/USER-GUIDE.md  → docs/manual/Postvak-User-Guide.docx / .pdf

Needs pandoc, LibreOffice (soffice) and Python with Pillow. Styling (fonts, colours, footer) comes from
docs/manual/reference.docx. Run after `node scripts/manual-screenshots.mjs` when the screens changed.
Usage: python3 scripts/build-manual.py [nl|en]
"""
import json, re, shutil, subprocess, sys, tempfile, zipfile
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
DOCS = ROOT / 'docs'
OUT = DOCS / 'manual'
GH = 'https://github.com/peopledisplaynl-coder/postvak/blob/main/'
VERSION = json.loads((ROOT / 'frontend' / 'package.json').read_text())['version']
MAXW, MAXH = 16.5, 21.0  # cm, A4 with 2 cm margins

LANGS = {
    'nl': dict(src='HANDLEIDING.md', out='Handleiding-Postvak', title='Handleiding Postvak',
               subtitle='Webmail voor je eigen hostingserver', month='oktober 2026', version='Versie',
               footer='Handleiding Postvak {v} · PeopleDisplay', page='Pagina', contents='Inhoud', lang='nl-NL',
               links={'INSTALL.nl.md': 'docs/INSTALL.nl.md', '../CHANGELOG.md': 'CHANGELOG.md'}),
    'en': dict(src='USER-GUIDE.md', out='Postvak-User-Guide', title='Postvak user guide',
               subtitle='Webmail for your own hosting server', month='October 2026', version='Version',
               footer='Postvak user guide {v} · PeopleDisplay', page='Page', contents='Contents', lang='en-GB',
               links={'INSTALL.md': 'docs/INSTALL.md', '../CHANGELOG.md': 'CHANGELOG.md', 'HANDLEIDING.md': 'docs/HANDLEIDING.md'}),
}
PAGE_BREAK = '\n```{=openxml}\n<w:p><w:r><w:br w:type="page"/></w:r></w:p>\n```\n'


def image(src, width_px, alt, per_row=1):
    w, h = Image.open(DOCS / src).size
    cm = 4.4 if per_row == 3 else min(MAXW / per_row - (0.3 if per_row > 1 else 0), int(width_px) / 800 * MAXW)
    if h / w * cm > MAXH:
        cm = MAXH * w / h
    return f'![{alt}]({DOCS / src}){{width={cm:.1f}cm}}'


def prepare(cfg):
    s = (DOCS / cfg['src']).read_text()

    def row(m):
        imgs = re.findall(r'<img src="([^"]+)" width="(\d+)" alt="([^"]*)">', m.group(0))
        if len(imgs) == 1:
            return '\n' + image(*imgs[0]) + '\n'
        return '\n' + ' '.join(image(src, wid, '', per_row=len(imgs)) for src, wid, _ in imgs) + '\\\n'

    s = re.sub(r'<p align="center">.*?</p>', row, s, flags=re.S)
    s = re.sub(r'<img src="([^"]+)" width="(\d+)" alt="([^"]*)">', lambda m: image(*m.groups()), s)
    s = s.replace('<sub>', '').replace('</sub>', '')

    # Heading ids as on GitHub, but starting with a letter (Word bookmarks).
    def head(m):
        gid = re.sub(r'[^\w\- ]', '', m.group(2).strip().lower()).replace(' ', '-')
        return f'{m.group(1)} {m.group(2)} {{#h-{gid}}}'
    s = re.sub(r'^(#{2,3}) (.+)$', head, s, flags=re.M)
    s = re.sub(r'\]\(#([^)]+)\)', r'](#h-\1)', s)
    for rel, target in cfg['links'].items():
        s = s.replace(f']({rel})', f']({GH}{target})')

    lines = s.split('\n')
    assert lines[0].startswith('# '), 'the manual must start with a # title'
    body = '\n'.join(lines[1:]).replace('\n---\n', '\n')
    contents = f'\n## {cfg["contents"]} {{#h-{cfg["contents"].lower()}}}'
    body = body.replace(contents, PAGE_BREAK + contents, 1)
    # Page break after the contents list (the first numbered list).
    body = re.sub(r'(\n21\. \[[^\n]+\]\(#h-[^)]+\)\n)', lambda m: m.group(1) + PAGE_BREAK, body, count=1)
    cover = f'''![]({ROOT / 'frontend' / 'public' / 'icon-512.png'}){{width=3cm}}

::: {{custom-style="Title"}}
{cfg['title']}
:::

::: {{custom-style="Subtitle"}}
{cfg['subtitle']}
:::

::: {{custom-style="Author"}}
{cfg['version']} {VERSION} · {cfg['month']} · PeopleDisplay
:::
{PAGE_BREAK}'''
    return cover + body


def reference(cfg, tmp):
    """The reference document with this language's footer."""
    src = OUT / 'reference.docx'
    dst = Path(tmp) / 'reference.docx'
    with zipfile.ZipFile(src) as zin, zipfile.ZipFile(dst, 'w', zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            data = zin.read(item.filename)
            if item.filename == 'word/footer1.xml':
                data = data.decode().replace('{{FOOTER}}', cfg['footer'].format(v=VERSION)).replace('{{PAGE}}', cfg['page']).encode()
            zout.writestr(item, data)
    return dst


def build(code):
    cfg = LANGS[code]
    with tempfile.TemporaryDirectory() as tmp:
        md = Path(tmp) / 'manual.md'
        md.write_text(prepare(cfg))
        docx = OUT / f'{cfg["out"]}.docx'
        subprocess.run(['pandoc', str(md), '-f', 'markdown+implicit_figures', '-t', 'docx',
                        f'--reference-doc={reference(cfg, tmp)}', '--shift-heading-level-by=-1',
                        '--metadata', f'lang={cfg["lang"]}', '-o', str(docx)], check=True)
        soffice = shutil.which('soffice') or shutil.which('libreoffice')
        subprocess.run([soffice, '--headless', '--convert-to', 'pdf', '--outdir', str(OUT), str(docx)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    print(f'{docx.relative_to(ROOT)} and .pdf')


for code in (sys.argv[1:] or LANGS.keys()):
    build(code)
