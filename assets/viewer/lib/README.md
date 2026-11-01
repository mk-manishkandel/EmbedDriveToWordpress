# Bundled preview libraries

Loaded only when a visitor opens a preview of that file type, inside a sandboxed frame. PDF.js and docx-preview come from `bin/build-viewer-libs.sh`, which bundles PDF.js from its published source without changing it. Nothing else from their npm packages is included.

| File | Project | Version | Licence |
|---|---|---|---|
| `pdf.min.js`, `pdf.worker.min.js` | [PDF.js](https://github.com/mozilla/pdf.js) (`pdfjs-dist` legacy build) | 4.10.38 | Apache-2.0 |
| `docx-preview.min.js` | [docx-preview](https://github.com/VolodymyrBaydalka/docxjs) | 0.4.1 | Apache-2.0 |
| `jszip.min.js` | [JSZip](https://github.com/Stuk/jszip) | 3.10.1 | MIT |
| `xlsx.full.min.js` | [SheetJS Community Edition](https://github.com/SheetJS/sheetjs) | 0.20.3 | Apache-2.0 |
