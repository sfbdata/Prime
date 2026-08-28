# Handoff — redesenho da `cobranca_objeto_show`

Data: 28/08/2026. Direção **1a aprovada pelo dono** ("coluna única").
Repo: `sfbdata/Prime`, branch `master`. Dado da tela: carteira TOP LIFE I, unidade **03-07**.

## O que abrir

| Arquivo | O que é |
|---|---|
| `Objeto 1A.dc.html` | O desenho aprovado, interativo. Abre direto no navegador (precisa do `support.js` ao lado). As 6 abas trocam, a linha da dívida expande, a seleção soma e a barra de acordo aparece. |
| `support.js` | Runtime do arquivo acima. Não faz parte da implementação — só serve para VER o desenho. |
| `assets/bluejus-white-transparent-nav.png` | Logo real da nav, tirada do repo (`bluejus-favicons/logo nav/`). |
| `estilos.css` | Os tokens e as classes novas, já em CSS, para colar em `app/public/css/cobrancas.css`. |

A implementação é **Twig + CSS do sistema**. Este HTML é referência visual e de comportamento.

## Contexto: o Ajuste 11 foi implementado e depois desfeito

`app/public/css/cobrancas.css` (≈ linha 728) registra que a grade de 2 colunas
(`.cob-grid`/`.cob-main`/`.cob-rail`) e o hero escuro (`.cob-hero*`) **foram removidos**: a página
voltou à coluna única, a pessoa virou a aba Responsáveis e a próxima ação virou faixa.

Este redesenho **não tenta ressuscitar o cockpit**. Ele mantém a coluna única e resolve a queixa de
hierarquia de outro jeito — um número herói no cabeçalho e a decomposição da dívida na linha —, com
o vocabulário visual já aprovado nas telas de Pasta e Carteira.

Duas direções foram desenhadas (`Objeto - 2 direções.dc.html` no projeto de design): 1a coluna única
e 1b com trilho lateral. **1a venceu.**

## Estrutura da tela

```
header teal (chrome existente)
nav: EXPEDIENTE (ativo) · DEMANDAS · PROCESSOS          ← nav real, não mexer
┌ card de cabeçalho ─────────────────────────────────────────────────────────┐
│ ‹ TOP LIFE I  breadcrumb            unidade 47 de 121   [‹] [›]            │
│ UNIDADE · selo "Cobrança ativa"      [Registrar contato][Simular][Planilha]│
│ H1 03-07                             [Judicializar][Encargos][Encerrar✗]   │
│ 161 obrigações em aberto · desde 08/2018 · responsável <a>                 │
├─────────────────────────────── hairline ───────────────────────────────────┤
│ TOTAL VENCIDO HOJE  R$ 37.653,96 (44px, danger)  │ faixa de prescrição     │
│ Principal · Juros e multa · Honorários (19px)    │ faixa Próxima ação      │
│ barra de composição + legenda + "Recuperado R$ 0,00"                       │
└────────────────────────────────────────────────────────────────────────────┘
abas (controle segmentado): Dívida 161 · Cobrança · Responsáveis 2 ·
                            Honorários · Documentos 0 · Histórico 162
conteúdo da aba, largura total
```

### Decisões de layout que mudam o que existe hoje

1. **Os 4 cards do vencido viram 1 herói + 3 apoios.** O `totalAtualizadoVencido` é o número de
   44px; `totalPrincipalVencido`, `totalEncargosVencido` e `honorariosVencidos` ficam em 19px ao
   lado. Os quatro fecham entre si por construção (já documentado no `CasoDetalheOutput`).
2. **Barra de composição do vencido** (Principal / Juros e multa / Honorários), em % dos mesmos
   três campos. À direita da legenda, `Recuperado R$ …` = `honorariosRecebidos + Σ pagamentos`
   (ver "Pendências").
3. **A faixa de prescrição sai do topo da página** e vira bloco fixo na direita do cabeçalho, com
   o link `Ver competência na dívida →` (âncora `#secao-divida`).
4. **Próxima ação fica logo abaixo da prescrição**, na mesma coluna — não mais faixa de largura
   total acima do conteúdo.
5. **As 6 ações do cabeçalho ficam juntas, em 2 linhas de 3**: frequentes em cima, raras embaixo.
   O dropdown `⋯` foi eliminado. `Encerrar cobrança` desabilitado continua **ensinando** o que
   falta (`title` com o saldo em aberto).
6. **A aba Dívida é a primeira e a padrão** (hoje é Cobrança). Ela responde "quanto deve".

## A linha da dívida (mudança principal)

A faixa de pílulas com todos os encargos sempre visível **sai**. No lugar:

```
grid-template-columns: 34px 108px minmax(0,1fr) 110px 120px 130px 128px 34px;
padding: 11px 16px;
/*         ☐    venceu   o que é   Original Acréscimos  Total   ações   ⌄  */
```

| Coluna | Fonte (`ObrigacaoOutput`) |
|---|---|
| ☐ seleção | `.jp-check` — contrato de seleção intacto |
| Venceu em + "há N dias" | `vencimentoOriginal` (o relativo em `--bs-danger` quando vencida) |
| O que é + sub (unidades associadas · `#id`) | `descricao` |
| **Original** | `valorOriginal` |
| **Acréscimos** | `juros + multa + correcao + honorarios` — soma na leitura, nunca no Twig |
| **Total** | `valorAtual` (15px, bold) |
| Receber + `⋯` | mesmos modais de hoje |
| `⌄` | expande o detalhe |

O **detalhe expandido** mostra os cinco componentes rotulados com o % de incidência (Original ·
Juros 1% a.m. pró-rata · Multa 2% · Correção · Honorários 20%) — é o mesmo conteúdo da faixa de
pílulas, agora sob demanda. Encargo zerado aparece em cinza claro, não some.

Linha vencida: fundo `#fffbfa` + espinha de 3px à esquerda (mesma régua do `.jp-obr.is-vencida`).
Linha selecionada: fundo verde-claro + espinha `--jp-accent`. Linha expandida: espinha danger cheia.

Barra de seleção: sticky no rodapé, escura, com contagem, soma e "Fazer acordo com estas"
(`#barraSelecaoDivida`, `[data-selecao-qtd]`, `[data-selecao-total]` preservados).

## As seis abas

| Aba | Conteúdo no desenho | Origem |
|---|---|---|
| **Dívida** (padrão) | lista + estado vazio de "já pago" + estado vazio de acordo | `obrigacoesAvulsasEmAberto`, `gruposAcordo`, `totalPagoDasAvulsas` |
| **Cobrança** | editor de anotação (com barra de formatação) + qualificar contato + "O que já entrou" | `historico`, `qualificacoes`, `pagamentos` |
| **Responsáveis** | **igual ao que está no repositório hoje** — não redesenhar | `fichaCobrada`, `vinculos`, `qualificacoes` |
| **Honorários** | A receber / Já recebido + configuração (4 campos) + lista por obrigação com rodapé somado | `honorariosEmAberto`, `honorariosRecebidos`, `formaHonorariosLabel`, `percentualHonorarios`, `baseHonorariosComposta`, `carenciaHonorariosDias` |
| **Documentos** | busca + Nova pasta + Enviar + alternador lista/grade + dropzone vazia com as 4 categorias | `pasta-arquivos.js`, `CategoriaDocumentoCobranca` |
| **Histórico** | timeline com chips de tipo + filtros (Tudo · Contatos · Dinheiro · Obrigações · Anotações) + "carregar mais" | `EventoHistoricoOutput` |

> **Responsáveis:** decisão do dono em 28/08 — a aba fica **exatamente como está no repositório**
> (nome em caixa própria, Telefones com o `+` tracejado, ficha de 4 campos, painel de qualificação
> à direita, "Outras pessoas vinculadas" em accordion, rodapé Voltar / Próxima unidade). O desenho
> só reveste com a mesma tipografia das outras abas.

## Contrato de JS a preservar (spec do Ajuste 11, §2)

Nada disso pode sumir na reescrita da marcação:

- Abas: `#objetoTabs`, painéis `#tab-cobranca`, `#tab-documentos`, `#tab-historico`, botão
  `#documentos-tab` (o `pasta-arquivos.js` restaura a aba por esse id).
- Dívida: `#secao-divida`, `.jp-obr`, `.jp-check`, `#barraSelecaoDivida`,
  `[data-acao="acordar"|"acordar-selecionadas"|"limpar-selecao"]`.
- Modais e seus `data-*`: `#modalRegistrarPagamento` (+ `data-acao="receber"`,
  `data-obrigacao-id`, `data-valor-centavos`, `data-bruto-centavos`), `#modalCriarAcordo`,
  `#modalEditarObrigacao`, `#modalExcluirObrigacao`, `#modalCorrigirPagamento`,
  `#modalRomperAcordo`, `#modalCancelarAcordo`, `#modalEncerrarVinculo`,
  `#modalRegistrarTentativa`, `#modalConcluirAcao`, `#modalDefinirAcao`, `#modalJudicializar`,
  `#modalEncerrarCaso`, `#modalAlterarPessoa`, `#modalVincularPessoaObjeto`, `#modalNovaPessoa`,
  `#modalConfigEncargosObjeto`, `#modalFichaPessoa`.
- Erro B5: `[data-modal-erro]` / `[data-modal-erro-acao]`.
- Âncora `#secao-divida` (alertas e redirects dependem dela).

Regra de ouro: **reveste primeiro, sem apagar id/classe/`data-*`**; só depois remove o que
comprovadamente não é gancho.

## Pendências de back-end

Tudo no desenho sai de `MontarDetalheObjetoUseCase` / `CasoDetalheOutput` / `ObjetoDetalheOutput`,
com três exceções:

1. **"Recuperado R$ …" na legenda da barra** — precisa do total recebido na cobrança
   (Σ `PagamentoOutput::valorTotal`, ou um campo somado no UseCase, como já se faz com os quatro
   cards). Somar no Twig não serve: é dinheiro.
2. **Percentuais da composição** (59,5% / 23,8% / 16,7%) — derivam dos três campos do vencido;
   podem ser calculados na leitura, junto dos totais.
3. **Filtros do Histórico** (Contatos / Dinheiro / Obrigações / Anotações) — exigem um recorte por
   `TipoEventoHistorico` no `EventoHistoricoRepository`. Sem isso, entregue a timeline sem os
   chips (nada mais no desenho depende deles).

`Encargos` como rótulo do botão é abreviação de "Editar configuração de encargos"
(`cobranca_objeto_configurar_encargos`) — a rota não muda.

## Ordem sugerida de implementação

1. Cabeçalho: herói + 3 apoios + barra de composição + as 6 ações em 2 linhas.
2. Prescrição e Próxima ação na coluna direita do cabeçalho.
3. Abas na ordem nova, com Dívida como padrão.
4. Linha da dívida: colunas Original/Acréscimos/Total + detalhe expansível (remover a faixa de
   pílulas e o CSS `.jp-obr-encargos`/`.jp-obr-enc*` junto — CSS órfão vira armadilha).
5. Honorários, Documentos, Histórico — revestimento.
6. Responsáveis: **não mexer** além da tipografia.

Smoke obrigatório antes do commit (spec do Ajuste 11 §7): "Receber" na linha, gerador de acordo com
seleção, editar/excluir pelo `⋯`, upload restaurando a aba Documentos, reabertura de modal com erro
(B5) e os dois temas.
