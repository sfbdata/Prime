# Spec — Política de Privacidade (leitura pública)

**Risco: MÉDIO.** Não mexe em ponto eletrônico nem em identidade User/Tenant, mas altera duas
camadas de autorização: acrescenta entrada em `security.yaml` (documentada em
`docs/AUTORIZACAO.md`) e alarga a lista branca de **dois** listeners de portão.

Escrita depois da implementação, o que é uma falha de processo do ciclo — o item 9 da revisão.
Registrada aqui para que a re-revisão tenha alvo, e para que a decisão fique fora da cabeça de
quem implementou.

## Problema

O rodapé do login trazia um link "Privacidade" apontando para `#` desde o redesenho da tela. O
documento não existia. Fora do login, a Política não era citada em lugar nenhum.

## Decisões do dono (19/08/2026)

| # | Decisão | Consequência |
|---|---|---|
| 1 | **Só leitura.** A Política não entra no fluxo de aceite. | `TermoVigente::VERSAO` não muda; ninguém é parado em tela de reaceite. |
| 2 | Hospedagem: **Hostinger, São Paulo/Brasil**. | Anexo I, linha 1. Medido por RDAP do RIPE (bloco `HOSTINGER-HOSTING`) + painel. |
| 3 | Google Drive do sync: **conta Gmail comum**. | Anexo I, linha 3 — Conteúdo do Usuário nos EUA. Linha que o modelo do advogado não previa. |
| 4 | E-mail transacional: **Gmail hoje**, migração decidida. | Anexo I, linha 2. Ao migrar: atualizar a linha e subir para 1.1. |
| 5 | Pagamento / assinatura eletrônica / IA: **remover as 3 linhas**. | Nada disso existe. A promessa de não usar dado para IA continua no corpo do capítulo 11. |
| 6 | **`noindex`**: a página não aparece em buscadores. | `<meta name="robots" content="noindex, follow">` na página; `X-Robots-Tag: noindex` no PDF. |
| 7 | Limitador na rota do PDF (em vez de PDF estático). | Mantém fonte única; 30/min, folgado pelo `getClientIp()` compartilhado. |

## Contrato

- `GET /politica-de-privacidade` → HTML, **pública**, canônica, pesquisável, com âncora por capítulo.
- `GET /politica-de-privacidade.pdf` → mesmo partial via dompdf, limitado a 30/min.
- Fonte única: `app/templates/legal/_politica_privacidade_texto.html.twig`. Nunca duplicar o texto.
- Versão e data: só de `App\Legal\PoliticaPrivacidadeVigente`. Nunca literal no template.
- Ligações: rodapé do login, cadastro público, aceite de convite, tela de aceite dos Termos,
  menu do usuário (`base.html.twig` **e** `layout_peticionar.html.twig`).
- No cadastro e no convite o link é de **ciência, fora do bloco de aceite** — item 1.3 do próprio
  documento. Pôr dentro criaria um segundo aceite que ninguém decidiu colher.

## Invariantes (o que os testes travam)

1. A página abre **sem autenticação** (`PUBLIC_ACCESS` antes do coringa `^/`).
2. A página abre para **usuário logado sem escritório selecionado** e para **usuário sem os Termos
   aceitos**. São **dois** portões distintos — `TermoAceiteListener` (prioridade 7) e
   `TenantContextValidatorListener` (6) — e a rota precisa estar na lista branca **dos dois**.
   Liberar só um deixa o link quebrado justamente para quem está parado nas telas que o oferecem.
3. **Nenhum campo em branco da minuta** sobrevive no HTML renderizado.
4. O Anexo I tem exatamente **3 linhas**, sem pagamento, assinatura eletrônica ou IA.
5. O PDF tem conteúdo — piso de bytes e contagem de páginas, não só o cabeçalho `%PDF`.
6. A página traz `meta robots noindex`; o PDF traz `X-Robots-Tag`.
7. A página HTML **não** consome a cota do limitador (só o PDF custa CPU).

## Fora de escopo

- Aceite da Política (decisão 1).
- Painel de preferências de cookies que o capítulo 10 menciona — não existe no sistema.
- Migrar o Drive para Workspace.
- A senha do Gmail versionada em `app/.env` — frente própria.

## Limites conhecidos, medidos

- **O PDF não é pesquisável.** dompdf usa `/Encoding /Identity-H` com `/ToUnicode` identidade.
  Desligar `isFontSubsettingEnabled` vai de 102 KB para 1,3 MB e não corrige. A versão
  pesquisável é a HTML.
- **Nenhum teste sabe o dia do deploy.** `DATA_PUBLICACAO` é literal no teste para que mudá-la
  seja ato consciente, mas trocá-la no deploy é item de checklist, não de suíte.
- **`getClientIp()` devolve o IP do nginx** enquanto `SYMFONY_TRUSTED_PROXIES` não for confirmado
  na VPS: o limitador tem uma chave só para todos. Daí o limite folgado.

## Pendente de decisão jurídica (não bloqueia o código)

O **capítulo 13** ainda enumera "processamento de pagamento" e "assinatura eletrônica" como
suboperadores **contratados**, contradizendo o Anexo I que ele mesmo aponta como lista
autoritativa. E afirma "contrato escrito com cada um deles", incompatível com o Drive em conta
Gmail comum. Proposta levada ao dono: o capítulo 13 parar de enumerar contratações e remeter ao
Anexo I — assim o texto fica verdadeiro hoje e não precisa ser reescrito quando pagamento existir.
**Aguarda o advogado.**
