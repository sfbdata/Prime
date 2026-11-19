# Sistema em inglês e Opções avançadas (frente E)

Sessão 6a, 19/11/2026. Pedido do Farlei de 19/11 (itens E1 a E3 de `bluejus-coordenacao/pedidos-farlei-1911.md`):
sistema em inglês, bandeira no topo para trocar o idioma e "Opções avançadas" nas configurações. Continua a spec
`idioma-do-sistema.md`, que já entregou a base: idioma gravado no perfil (`user_profiles.idioma`), listener de locale,
seletor (menu do usuário e Meu Perfil), filtros `|data`, `|data_hora`, `|hora`, `|numero` e o `BJFormato` no JS,
e os 72 arquivos `pt_BR` do catálogo. Não há desenho para a bandeira nem para as Opções avançadas: os componentes
seguem a convenção da moldura (botão `navtop-action-btn`, modal `mu-modal` e linha `mp-pref` do Meu Perfil).

Risco BAIXO (preferência pessoal; nada de permissão, tenant nem ponto).

## Estado

- PR 1 (branch `idioma-opcoes-avancadas`, migration `Version20261119110000`): bandeira e Opções avançadas.
- PRs de tradução: pendentes.

## 1. Bandeira no topo (E2)

- Botão na barra do topo, antes do botão de tela cheia, com a bandeira do idioma em uso (Brasil, Estados Unidos,
  Espanha; desenho em SVG, sem emoji, que no Windows vira letras). Abre o mesmo modal "Idioma do sistema" do menu do
  usuário. Some quando o recurso `idioma` está escondido pelo Master (mesmo critério do item do menu).
- Acessibilidade (pedido A3): o botão tem nome ("Idioma: Português (Brasil)") e dica; a bandeira não é o único sinal,
  o modal mostra sigla e nome.

## 2. Opções avançadas (E3)

Linha nova no bloco "Preferências" do Meu Perfil, logo abaixo de "Idioma do sistema", que abre o modal "Opções
avançadas" com quatro escolhas e um botão Salvar (grava tudo de uma vez e recarrega a página):

| Escolha | Opções | Padrão (nulo no banco) |
|---|---|---|
| Idioma | os de `Idioma::oferecidos()`; os "em breve" desabilitados | Português (Brasil) |
| Fuso horário | lista fixa (`FusoHorario`): Brasília, Fernando de Noronha, Manaus, Rio Branco, Lisboa, Londres, Madri, Nova York, Chicago, Denver, Los Angeles, UTC | Brasília |
| Formato de data | Automático (pelo idioma), 31/12/2026, 12/31/2026, 2026-12-31 | Automático |
| Números e valores | Automático (pelo idioma), 1.234,56, 1,234.56 | Automático |

Decisões (pela recomendação, avisadas ao Coordenador em 19/11):

1. **Moeda continua real.** Os valores do sistema são em reais (causas, honorários, cobranças); converter para dólar
   exigiria cotação e mudaria o valor jurídico. Muda só a forma de escrever o número (`R$ 1.234,56` ou
   `R$ 1,234.56`). O modal diz isso.
2. **Fuso só nos registros de data e hora** (`|data_hora`, `|hora` e `BJFormato.hora/dataHora`): "quando aconteceu".
   Nunca em data pura (`|data`, `BJFormato.data`): prazo e audiência têm o dia da Justiça brasileira, e converter a
   meia-noite de Brasília para Los Angeles mostraria o dia anterior. Os campos de digitar data e hora continuam no
   horário de Brasília; o modal avisa.
3. **Formatos só onde a tela já usa os filtros** (`|data` 101, `|data_hora` 57, `|hora` 38, `|numero` 7). Os
   `|date('d/m/Y')` e `number_format` fixos que restam (cerca de 250) passam aos filtros nos PRs de tradução, área por
   área, junto com o texto (regra da entrega 2 da spec `idioma-do-sistema`).
4. Sem requisição (e-mail em comando) vale o padrão: português, Brasília, automático.

Técnica:

- `user_profiles`: 3 colunas novas, nulas (`fuso_horario` 40, `formato_data` 10, `formato_numero` 10), enums
  `FusoHorario`, `FormatoDeData`, `FormatoDeNumero` em `Profile/Enum/`. Migration `Version20261119110000`, sem backfill.
- `SalvarOpcoesAvancadasUseCase` (Profile): valida e grava as quatro escolhas; idioma "em breve" é recusado sem gravar.
  `POST /perfil/opcoes-avancadas` (JSON, CSRF `ajax`), como o `/perfil/idioma`.
- `FormatoDaRequisicaoListener` (Profile, `kernel.request`, depois do firewall): lê as escolhas (guardadas na sessão
  com o id da pessoa, como o idioma) e põe na requisição os atributos `_bj_fuso`, `_bj_formato_data` e
  `_bj_formato_numero`. O `Shared/Formato` lê só esses atributos, sem depender do domínio Profile.
- `<html>` ganha `data-bj-fuso`, `data-bj-formato-data` e `data-bj-formato-numero` (só quando escolhidos) para o
  `BJFormato`.

## 3. Inglês (E1)

- Um PR por lote: os arquivos `translations/<bloco>/messages+intl-icu.en.yaml` dos 72 blocos, com as mesmas chaves
  do `pt_BR`, e os templates da área que ainda têm texto fixo passam ao catálogo (com os formatos fixos de data e
  número trocados pelos filtros).
- Tradução feita uma vez no desenvolvimento, com glossário jurídico fixo (pasta = folder? case file; Push; CNJ...),
  revisável depois; nada de tradução automática na tela (spec `idioma-do-sistema` §3.1).
- Guarda de teste: todo arquivo `en` tem exatamente as chaves do `pt_BR` do mesmo bloco, e os parâmetros `{x}` iguais.
- **Liberação:** o inglês sai do "em breve" (`Idioma::ativo()`) só no último lote, com tudo traduzido: o cliente
  nunca vê tela meio em inglês, meio em português (Coordenador, 19/11). Até lá a bandeira mostra o Brasil e o modal
  mostra o inglês "em breve".

## 4. Arquivos quentes

`app/templates/base.html.twig` (linha do `<html>` e a bandeira no topo): avisado ao Coordenador. A frente C também
mexe no topo do base; quem entrar depois traz o master. Migration: `Version20261119110000`.
