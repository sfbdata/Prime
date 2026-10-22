---
tipo: novidade
---
## Changelog
- Trabalho Externo: campanha por e-mail do Master (spec §4.10, desenho 06). Monta, mostra e exporta; não envia. Modal "Campanha por e-mail · Master" aberto pelo menu ⋮ ("Campanha por e-mail…"), pelo endereço `#camp=1` e pelo clique no KPI ALERTAS POR E-MAIL do painel (o `ir` passa a ser `{acao: "campanha"}`).
- Estado de hoje: a campanha sai com 0 destinatários. As três fontes estão desligadas até o dono decidir, numa regra só (`DestinatariosDaCampanha`): currículos (`CURRICULOS_LIBERADOS = false`, o aceite do currículo não cobre e-mail de campanha), inscritos nos alertas (entram só com o e-mail confirmado, que depende da 5d) e e-mails dos ofertantes no público Escritórios (`ESCRITORIOS_LIBERADOS = false`, botão desabilitado com a dica "A confirmar"). O modal diz quantos ficam de fora e por quê. O modal, a prévia e o HTML funcionam; o CSV responde "Sem destinatários".
- Regra quando liberada: currículos com o aceite LGPD nos filtros de UF e área, nunca de conta desativada; inscritos nos alertas nos dois públicos, como no desenho; sem e-mail repetido; ponto de troca do descadastro da 0d marcado no código.
- `GET /trabalho-externo/api/campanha` (`trabalho_externo_campanha`, `?pub=cand|esc&uf=&area=&assunto=`): públicos, filtros, assunto padrão, número de destinatários, quem fica de fora e por quê, vagas abertas no e-mail, resumo e a prévia do HTML com o link da página pública geral (não grava nada).
- `POST /trabalho-externo/campanha/destinatarios.csv` (`trabalho_externo_campanha_csv`, CSRF, filtros no corpo JSON): E-mail, Nome, Origem e UF, no formato das outras planilhas (BOM, `;`, aspas, proteção contra fórmula). Lista vazia: 404 em JSON com o toast "Sem destinatários".
- `POST /trabalho-externo/campanha/email.html` (`trabalho_externo_campanha_html`, CSRF, anexo `email-campanha-bluejus.html`) e `POST /trabalho-externo/api/campanha/registro` (`trabalho_externo_campanha_registro`, `{acao: "copiar"}`, CSRF, devolve o HTML para a tela copiar): o e-mail do desenho (tabelas e estilos inline, gerado por Twig, texto escapado) com um link de vagas próprio com as vagas abertas nos filtros e o marcador `{{descadastrar}}` no rodapé para a ferramenta de envio trocar.
- Só o Master de verdade: Equipe, externo e prévia `?como=` recebem 403 em JSON. Cada exportação (CSV, baixar e copiar o HTML) fica no log de auditoria (canal `audit`) com quem, quando, público, filtros e quantidade, nunca a lista. Sem migration.
- O download da lista de alertas por e-mail (§4.9) também passa a registrar no canal `audit`: no canal padrão, a produção descartava o registro.
- O item "Campanha por e-mail" do menu do Master (e da aba Master do Meu Perfil) deixa de abrir "em preparação" e leva ao modal da campanha no Trabalho Externo (`#camp=1`, desenho 06 l.1078).

## Para o cliente
- O Master agora monta uma campanha por e-mail das vagas abertas: escolhe o público, a UF, a área e o assunto, vê a prévia do e-mail e baixa o HTML. A lista de destinatários fica vazia até a definição de quem autorizou receber campanhas.
