---
tipo: correcao
---
## Changelog
- Auditoria 5.03: o currículo e o termo do Trabalho Externo imprimem por iframe com sandbox sem scripts (`imprimirEmQuadro`), não mais por `document.write` numa janela da mesma origem.
- Auditoria 5.04: CSP do /master com nonce por requisição (`NonceDaCsp`, função Twig `csp_nonce()`), sem `'unsafe-inline'` nem CDN em `script-src`; o editor do blog (novo/editar) passa a ter a CSP do painel e a prévia uma política estrita. Teste lê o HTML das páginas e confere contra a CSP (zero violações).
- Auditoria 5.08: jQuery, Bootstrap, OverlayScrollbars, Select2, AdminLTE e qrcode-generator servidos de `app/public/js/vendor/` (arquivos iguais aos do npm, com VERSION e sha384). Teste de arquitetura: script de CDN só com `integrity` (9 legados tolerados, a lista só encolhe).
- Auditoria 5.06: peça de texto limpa no servidor antes de gravar (`SanitizadorDePecaDeTexto`): sem script, on*, iframe, `javascript:`; imagem só do escritório; tabela, estilo e quebra de página preservados.
- Auditoria 5.07: JSON do modal "Mover para" com as flags HEX dentro do `<script>`.
- Auditoria 5.09: link do inteiro teor do DJEN só `http(s)`, no mapper e no `djen/show`.
- Auditoria 5.10: dica de clientes do Expediente montada com nós DOM (`textContent`).
- Auditoria 4.06 (passo 2): `3ee0082a:app/.env:url-com-credencial:14` e `:15` no `.gitleaksignore`, depois da conferência do Samuel em 05/11/2026 (runbook atualizado).

## Para o cliente
- Correções de segurança: nomes e textos digitados (clientes, marcadores, peças, currículos) não conseguem mais rodar código escondido nas telas do sistema.
- As bibliotecas que montam as telas passam a vir do próprio BlueJus, e não de sites de terceiros. O visual e o uso continuam iguais.
