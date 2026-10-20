---
tipo: novidade
---
## Changelog
- Trabalho Externo: campanha por e-mail do Master (spec §4.10, desenho 06). Monta, mostra e exporta; não envia. Modal "Campanha por e-mail · Master" aberto pelo menu ⋮ ("Campanha por e-mail…"), pelo endereço `#camp=1` e pelo clique no KPI ALERTAS POR E-MAIL do painel (o `ir` passa a ser `{acao: "campanha"}`).
- `GET /trabalho-externo/api/campanha` (`trabalho_externo_campanha`, `?pub=cand|esc&uf=&area=&assunto=`): públicos, filtros, assunto padrão, número de destinatários, quem fica de fora e por quê, vagas abertas no e-mail, resumo e a prévia do HTML com o link da página pública geral (não grava link de vagas).
- `GET /trabalho-externo/campanha/destinatarios.csv` (`trabalho_externo_campanha_csv`): E-mail, Nome, Origem e UF, no formato das outras planilhas (BOM, `;`, aspas, proteção contra fórmula). Lista vazia: 404 em JSON com o toast "Sem destinatários".
- `GET /trabalho-externo/campanha/email.html` (`trabalho_externo_campanha_html`, anexo `email-campanha-bluejus.html`) e `POST /trabalho-externo/api/campanha/registro` (`trabalho_externo_campanha_registro`, `{acao: "copiar"}`, CSRF): o e-mail do desenho (tabelas e estilos inline, gerado por Twig) com um link de vagas próprio com as vagas abertas nos filtros e o marcador `{{descadastrar}}` no rodapé para a ferramenta de envio trocar.
- Destinatários numa regra só (`DestinatariosDaCampanha`): currículos com o aceite LGPD nos filtros de UF e área, sem e-mail repetido. Inscritos nos alertas ficam de fora até confirmarem o e-mail; e-mails dos ofertantes (público Escritórios) desligados até a decisão do dono, com o botão desabilitado e a dica "A confirmar".
- Só o Master de verdade: Equipe, externo e prévia `?como=` recebem 403 em JSON. Cada exportação (CSV, baixar e copiar o HTML) fica no log da aplicação com quem, quando, público, filtros e quantidade, nunca a lista. Sem migration.

## Para o cliente
- O Master agora monta uma campanha por e-mail das vagas abertas: escolhe o público, a UF, a área e o assunto, vê a prévia do e-mail e baixa o HTML e a lista de quem autorizou o contato para enviar pela ferramenta de e-mail em massa do escritório.
