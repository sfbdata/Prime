# Spec: Trabalho Externo, desenho 25.10.26 (aba Pagamento e Painel do Escritório)

Desenho: `docs/design/redesign-25-10-26/06 - Trabalho Externo -25.10.26.dc.html` (1461 linhas).
Anterior: `docs/design/redesign-24-10-26/` (spec `trabalho-externo-24-10-26.md`, já no master).
Risco: **MÉDIO** (dinheiro; QR e confirmação de recebimento). Linhas "l." são do desenho novo.

## Diferença para o 24/10 (diff real, 7 trechos)

1. **Aba "Pagamento"** (l.620 a 637 e JS `pg:` l.1417 a 1427, `crc16`/`pix` l.825).
2. Aba entra entre Chat e Histórico; Convidado não a vê (l.1361).
3. Menu lateral "Dashboard" vira **"Painel do Escritório"** (l.1365), mesmo ícone e link.
4. Scripts `bj-monitor`, `bj-contas`, `bj-compartilhar`: moldura do protótipo, fora.
Nenhuma outra linha mudou. O toast do despacho ainda diz "aba Despacho" no desenho; segue o texto "no Resumo" já decidido.

## Decisões (coordenador, 25/10)
- QR Code em SVG gerado no servidor (sem CDN). Serviço novo no TE, sem mexer no Contribua.
- "Interno" (Master e Equipe) confirma o recebimento, como o desenho. O dono pode restringir depois (registrar no PR).
- O valor vem da **última proposta aceita**.
- Migration `Version20261024150700` reservada.

## Comportamento

**Aba Pagamento** (`dz.pagamento`, montado no servidor; Convidado não recebe o campo nem vê a aba).
- Faixa de status, 13.5px bold, ícone `bi`:
  - Aguardando pagamento: `#0a6fa0` sobre `#e5f1f7`, `bi-qr-code`.
  - Comprovante enviado: texto "Comprovante enviado em {dd/mm/aaaa hh:mm} · aguardando confirmação do BlueJus", `#9a5b00` sobre `#fff8e6`, `bi-hourglass-split`.
  - Pago: "Pagamento confirmado em {data}", `#1e7b45` sobre `#e9f6ee`, `bi-check-circle-fill`.
- Sem proposta aceita: texto "O pagamento fica disponível quando uma proposta for aceita. O valor e o QR Code PIX são gerados automaticamente." (13.5px, `#5b7383`). Sem QR.
- Com proposta aceita: QR 170x170 (borda `#e1e8ed`, padding 8px), "VALOR DA PROPOSTA ACEITA" (11px, bold, `#4a6373`), valor `R$ x.xxx,xx` (24px, `#12242f`), forma de pagamento da proposta (padrão "À vista"), "Favorecido: BlueJus Gestão e Tecnologia LTDA", "CNPJ 66.803.163/0001-10 · identificação {txid}".
- QR e código copia-e-cola só enquanto não pago. Código em monospace 11px, selecionável. Botão "Copiar código PIX" (vira "Copiado" por 2 s) e, para o interno, "Enviar no chat".
- Solicitante, sem comprovante e não pago: "Já paguei · enviar comprovante" (PDF ou imagem, borda tracejada `#9fc3d8`, fundo `#f5fafd`).
- Com comprovante: botão com o nome do arquivo que baixa o comprovante.
- Interno, não pago: "Confirmar recebimento" (verde `#1e7b45`).

**BR Code**: mesmo formato do desenho (campo 01 = `12`, valor com 2 casas, nome `BLUEJUS GESTAO E TECNOLOG`, cidade `BRASILIA`, txid = código da demanda só alfanumérico, no máximo 25 caracteres, CRC16-CCITT). Serviço novo `PixDaDemanda`; teste confere o CRC e a estrutura contra o algoritmo do desenho.

**Comprovante** (arquivo real, skill armazenamento-de-arquivos): `DocumentoDemanda` ganha `comprovante` (bool). Fica fora de DOCUMENTOS. Máximo 1 por demanda (desenho: só envia em "aguardando"), 20 MB, PDF ou imagem. Baixa pela rota de documentos, com a política existente (Convidado nunca baixa). Mensagem de sistema: "💳 Comprovante de pagamento PIX enviado ({arquivo}). Aguardando confirmação."

**Confirmar recebimento**: usa `AcaoDemanda::RegistrarPagamento` (pagoEm, histórico `pago`, aviso WhatsApp fase Pago, aviso de andamento) com o diálogo de confirmação da tela, texto "Confirmar o recebimento de R$ x via PIX?". Mensagem de sistema "✅ Pagamento de R$ x confirmado via PIX. A execução pode começar." e histórico "PIX R$ x". Toast "Pagamento confirmado" / "O solicitante foi avisado no chat e por e-mail." O e-mail sai pelo aviso de andamento existente.
Só em situação em que a ação já vale (Aceita, Execução, Validação, Concluída) e `pagoEm` nulo. Sem proposta aceita, a aba não oferece confirmação.

**Enviar no chat**: mensagem de sistema "💳 Pagamento disponível: R$ x via PIX para BlueJus Gestão e Tecnologia LTDA (CNPJ 66.803.163/0001-10), identificação {txid}. Veja o QR Code na aba Pagamento." e abre a aba Chat.

**Painel do Escritório**: chave `moldura.menu_lateral.dashboard` passa a "Painel do Escritório".

## Segurança (dinheiro)
- O valor e o txid saem só da proposta aceita no servidor; o cliente nunca informa valor.
- Solicitante só envia comprovante da própria demanda; outro escritório 404; Convidado 403/sem campo; prévia `?como` não grava.
- Confirmar recebimento: só interno, idempotente (segunda chamada não duplica aviso).
- Comprovante e valor não aparecem para quem não pode ver (mesma política dos documentos).

## Dado novo
`trabalho_externo_documento.comprovante` boolean NOT NULL DEFAULT false. Nada mais; quem enviou e quando já estão no documento.

## Dependência
Biblioteca de QR em PHP puro com saída SVG (sem GD): `bacon/bacon-qr-code`. Mexe em `composer.json/lock` (arquivo quente, coordenador avisado).

## Conferência
Checklist ✓/✗ item a item contra o desenho (l.620 a 637, 825, 1361, 1365, 1417 a 1427), mais revisão do `feature-review-agent` antes do PR.
