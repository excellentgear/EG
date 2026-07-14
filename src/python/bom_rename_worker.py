#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
bom_rename_worker.py — 叫料文件自動改檔名工具：唯讀分析（OCR 草稿 + 加強對比預覽）
不修改/搬移任何來源檔案，所有檔案操作都在 PHP 端進行。

用法：
  python bom_rename_worker.py --input in.json --previewdir <dir> --output out.json

in.json:
  {
    "files": ["絕對路徑1", "絕對路徑2", ...],
    "crop": {"left": 0, "top": 0, "width": 35, "height": 100},   // 百分比
    "tesseract_cmd": "C:\\Program Files\\Tesseract-OCR\\tesseract.exe"
  }

out.json:
  {"files": [
    {"file": "原始檔名(basename)", "kind": "image"|"pdf", "preview": "prev_000.png"|null,
     "text_chars": 0, "ocr_used": true|false, "bom_drafts": ["B-1150907011", ...], "error": null|"訊息"}
  ]}

設計原則：任何一個檔案處理失敗都不能中斷整批——該筆記錯誤、bom_drafts 給空陣列，繼續下一筆。
"""
import argparse
import json
import os
import re
import sys
import traceback

BOM_RE = re.compile(r'B[-\s]?(\d{10})')


def dedupe_keep_order(items):
    seen = set()
    out = []
    for it in items:
        if it not in seen:
            seen.add(it)
            out.append(it)
    return out


def extract_boms(text):
    return dedupe_keep_order(['B-' + m for m in BOM_RE.findall(text or '')])


def enhance_and_crop(pil_img, crop):
    """灰階 → autocontrast(cutoff=2) → 對比增強(2.0) → 依 crop% 裁切 → 放大2x。回傳 (整頁強化圖, 裁切放大後的OCR用圖)"""
    from PIL import ImageOps, ImageEnhance

    gray = pil_img.convert('L')
    ac = ImageOps.autocontrast(gray, cutoff=2)
    enhanced = ImageEnhance.Contrast(ac).enhance(2.0)

    w, h = enhanced.size
    left = int(w * (crop.get('left', 0) / 100.0))
    top = int(h * (crop.get('top', 0) / 100.0))
    cw = int(w * (crop.get('width', 35) / 100.0))
    ch = int(h * (crop.get('height', 100) / 100.0))
    left = max(0, min(left, w - 1))
    top = max(0, min(top, h - 1))
    right = max(left + 1, min(left + cw, w))
    bottom = max(top + 1, min(top + ch, h))

    cropped = enhanced.crop((left, top, right, bottom))
    zoomed = cropped.resize((cropped.width * 2, cropped.height * 2))
    return enhanced, zoomed


def ocr_text(zoomed_img, tesseract_cmd):
    import pytesseract
    if tesseract_cmd:
        pytesseract.pytesseract.tesseract_cmd = tesseract_cmd
    return pytesseract.image_to_string(zoomed_img, config='--psm 6')


def save_preview(pil_img, previewdir, idx):
    name = 'prev_%03d.png' % idx
    pil_img.convert('RGB').save(os.path.join(previewdir, name), 'PNG')
    return name


def process_image(path, crop, tesseract_cmd, previewdir, idx):
    from PIL import Image
    img = Image.open(path)
    enhanced, zoomed = enhance_and_crop(img, crop)
    preview = save_preview(enhanced, previewdir, idx)
    text = ''
    try:
        text = ocr_text(zoomed, tesseract_cmd)
    except Exception as e:
        return {
            'file': os.path.basename(path), 'kind': 'image', 'preview': preview,
            'text_chars': 0, 'ocr_used': False, 'bom_drafts': [],
            'error': 'OCR 執行失敗：' + str(e),
        }
    return {
        'file': os.path.basename(path), 'kind': 'image', 'preview': preview,
        'text_chars': 0, 'ocr_used': True, 'bom_drafts': extract_boms(text), 'error': None,
    }


def process_pdf(path, crop, tesseract_cmd, previewdir, idx):
    import fitz
    doc = fitz.open(path)
    all_text = []
    for page in doc:
        all_text.append(page.get_text())
    text_chars = sum(len(t.strip()) for t in all_text)

    if text_chars > 0:
        # 原生 PDF 有文字層：直接用文字層抓號碼，不跑 OCR
        boms = extract_boms('\n'.join(all_text))
        # 仍然產生第一頁預覽方便人工核對畫面
        preview = None
        try:
            pix = doc[0].get_pixmap(matrix=fitz.Matrix(2, 2))
            from PIL import Image
            img = Image.frombytes('RGB', (pix.width, pix.height), pix.samples)
            enhanced, _zoomed = enhance_and_crop(img, crop)
            preview = save_preview(enhanced, previewdir, idx)
        except Exception:
            preview = None
        doc.close()
        return {
            'file': os.path.basename(path), 'kind': 'pdf', 'preview': preview,
            'text_chars': text_chars, 'ocr_used': False, 'bom_drafts': boms, 'error': None,
        }

    # 掃描件（無文字層）：逐頁轉圖 → OCR，結果彙整成整份 PDF 的號碼清單
    boms_all = []
    preview = None
    from PIL import Image
    for pno, page in enumerate(doc):
        pix = page.get_pixmap(matrix=fitz.Matrix(2, 2))
        img = Image.frombytes('RGB', (pix.width, pix.height), pix.samples)
        enhanced, zoomed = enhance_and_crop(img, crop)
        if preview is None:
            preview = save_preview(enhanced, previewdir, idx)
        try:
            text = ocr_text(zoomed, tesseract_cmd)
            boms_all.extend(extract_boms(text))
        except Exception:
            pass  # 單頁 OCR 失敗不中斷其他頁
    doc.close()
    return {
        'file': os.path.basename(path), 'kind': 'pdf', 'preview': preview,
        'text_chars': 0, 'ocr_used': True, 'bom_drafts': dedupe_keep_order(boms_all), 'error': None,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--input', required=True)
    ap.add_argument('--previewdir', required=True)
    ap.add_argument('--output', required=True)
    args = ap.parse_args()

    with open(args.input, 'r', encoding='utf-8') as f:
        cfg = json.load(f)

    files = cfg.get('files', [])
    crop = cfg.get('crop', {'left': 0, 'top': 0, 'width': 35, 'height': 100})
    tesseract_cmd = cfg.get('tesseract_cmd') or None

    os.makedirs(args.previewdir, exist_ok=True)

    results = []
    for idx, path in enumerate(files):
        ext = os.path.splitext(path)[1].lower().lstrip('.')
        try:
            if not os.path.isfile(path):
                results.append({
                    'file': os.path.basename(path), 'kind': ext, 'preview': None,
                    'text_chars': 0, 'ocr_used': False, 'bom_drafts': [],
                    'error': '檔案不存在',
                })
                continue
            if ext == 'pdf':
                results.append(process_pdf(path, crop, tesseract_cmd, args.previewdir, idx))
            elif ext in ('jpg', 'jpeg', 'png', 'bmp', 'tif', 'tiff'):
                results.append(process_image(path, crop, tesseract_cmd, args.previewdir, idx))
            else:
                results.append({
                    'file': os.path.basename(path), 'kind': ext, 'preview': None,
                    'text_chars': 0, 'ocr_used': False, 'bom_drafts': [],
                    'error': '不支援的檔案格式',
                })
        except Exception as e:
            results.append({
                'file': os.path.basename(path), 'kind': ext, 'preview': None,
                'text_chars': 0, 'ocr_used': False, 'bom_drafts': [],
                'error': '處理失敗：' + str(e) + ' | ' + traceback.format_exc(limit=2),
            })

    with open(args.output, 'w', encoding='utf-8') as f:
        json.dump({'files': results}, f, ensure_ascii=False)

    print('OK ' + str(len(results)) + ' files processed')


if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        # 讓 PHP 端能明確判斷 worker 整體失敗（非個別檔案失敗）
        sys.stderr.write('WORKER FATAL: ' + str(e) + '\n' + traceback.format_exc())
        sys.exit(1)
