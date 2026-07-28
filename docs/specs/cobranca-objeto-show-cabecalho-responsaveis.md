# SPEC — Cabeçalho e aba Responsáveis do `cobranca_objeto_show`

**Data:** 2026-07-27 · **Risco:** MÉDIO (toca exibição de dinheiro e cria evento de histórico novo)
**Origem:** montagem visual enviada pelo dono do sistema, mais os ajustes que ele pediu por escrito.

A maquete é referência, não contrato. Onde ela diverge do que o sistema realmente tem, vale o que está
escrito aqui — e cada divergência está justificada.

---

## 1. Cabeçalho

Duas colunas a partir de 992px; empilha abaixo disso.

O cabeçalho inteiro vive numa **faixa** (`.cob-cab-painel`) destacada do corpo branco da página, e os
quatro cards de dinheiro são **blocos cheios** sobre ela. É o contraste da maquete, e ele tem função:
sobre a faixa, card claro com borda fina (a primeira entrega) desaparece no fundo. Acrescentado em
2026-07-27, junto com a revisão da §1.3.

### 1.1 Coluna esquerda — identidade

- **Trilha:** `Carteira <nome> › Unidade <identificação>`. O `Cond. Top Life` do meio da maquete é o
  cliente da carteira; sai por decisão do dono — a trilha do sistema tem dois níveis, não três.
- **Título:** `objeto.identificacao`, grande, com o badge de status (`caso.statusLabel`) ao lado.
- **Meta:** `objeto.descricao` · `N obrigações em aberto`.
  - **`Matrícula` NÃO entra.** A maquete mostra "Matrícula 128.447"; o sistema tem
    `referenciaExterna`, que é genérico e nem sempre é matrícula. Decisão do dono: não precisa.
  - `N obrigações em aberto` = obrigações listadas na aba Dívida que ainda não estão quitadas.

### 1.2 Coluna esquerda — dinheiro

Quatro cards que **somam entre si**, na ordem da maquete, todos sobre as obrigações **em aberto que
já venceram**:

| Card | Conteúdo |
|---|---|
| Principal | Σ `valorOriginal` das obrigações vencidas |
| Juros e multa | Σ (`juros` + `multa` + `correcao`) das obrigações vencidas |
| Honorários | Σ `honorarios` das obrigações vencidas |
| **Total vencido** | soma dos três acima, com a data do relógio da hidratação |

> **Revisto em 2026-07-27, decisão do dono.** Até aqui os cards eram sobre o **em aberto** e havia,
> abaixo deles, uma **linha fina** com `Total em aberto` (saldo exigível) e `Total vencido`. Os dois
> conjuntos saíram: o valor que o cabeçalho destaca **é o vencido**, e com isso a linha fina virou
> repetição. O que muda de verdade:
>
> 1. **Recorte novo (terceiro).** Além de *não quitada* e *não parcela de acordo desfeito*, os cards
>    exigem `vencimentoOriginal <= hoje`. A régua é a MESMA de `CalculadoraSaldo::saldoVencido`, com o
>    mesmo relógio — duas definições de "vencido" no módulo seria o começo de uma divergência.
> 2. **A parcela a vencer sai dos cards e fica em todo o resto**: continua na aba Dívida, na contagem
>    `N obrigações em aberto` da §1.1 e no `A receber` da aba Honorários. São perguntas diferentes.
> 3. **O `saldoExigivel` sai da tela** (não do sistema): segue governando o botão *Encerrar cobrança*,
>    o "pronto para encerrar" e o tooltip que diz quanto falta.
> 4. **Consequência assumida:** os cards são **brutos**. Pagamento PARCIAL numa obrigação vencida não
>    os reduz — só a quitação tira a obrigação da soma —, e agora não há mais o número líquido ao lado
>    para comparar. Isso já valia para os cards antes; o que saiu foi o contraponto.
> 5. **Caso-limite:** cobrança sem nada vencido mostra os quatro cards zerados. É a resposta certa
>    para a pergunta que o cabeçalho passou a fazer, e a linha meta continua dizendo que há obrigação
>    em aberto.
>
> Provas: `MontarDetalheCasoUseCaseTest::osTotaisDoCabecalhoSomamSoOVencido` e
> `::comNadaVencidoOsCardsZeram`; `CabecalhoObjetoShowTest::testCardsSomamSoOVencido` e
> `::testCardsSaoBrutosENaoOSaldo` (este último também trava que a linha fina não volta por descuido).

**Onde os totais são somados:** no `MontarDetalheCasoUseCase`, sobre **exatamente** o conjunto que a
aba Dívida lista (avulsas + parcelas dos grupos de acordo vigente), nunca no Twig. É dinheiro, e o
UseCase é onde há teste. Mesma regra que já vale para os totais da aba Honorários.

**"Em aberto" tem DUAS exclusões, não uma** (a segunda entrou em 2026-07-27, na Etapa 8, por achado
BLOQUEANTE da revisão da frente inteira):

1. obrigação **quitada**;
2. **parcela de acordo rompido/cancelado** (`parcelaDeAcordoDesfeito`).

A segunda não é detalhe: romper um acordo **devolve a obrigação original ao exigível** e deixa as
parcelas mortas na lista solta. Somar as duas contava o **mesmo dinheiro duas vezes** nos quatro
cards, na linha `N obrigações em aberto` e na escolha da competência da prescrição — e a própria aba
Dívida logo abaixo rotula essa parcela como *"histórico, fora do total em aberto"*. Com a exclusão, o
conjunto somado aqui é **idêntico** ao de `ObrigacaoRepository::doCasoExigiveis`, que é quem governa o
`saldoExigivel` — e é sobre esse conjunto que o recorte do **vencido** é aplicado depois, só para os
cards.

O **rodapé da aba Honorários** (`honorariosDasObrigacoes`) segue somando **tudo que a aba lista**,
inclusive a parcela morta e a quitada — ele existe para fechar com as linhas visíveis. O recorte
`A receber` (`honorariosEmAberto`) aplica as duas exclusões e **não** a do vencido: é a aba
Honorários, não o cabeçalho. O card `Honorários` (`honorariosVencidos`) é que aplica as três — os dois
campos existem por isso, e a diferença entre eles é o honorário do que ainda não venceu.

⚠️ **Isto muda um número já em produção**: o `A receber` da aba Honorários passa a ser menor em
qualquer caso que tenha acordo rompido/cancelado com parcela não quitada. É correção de dobra, não
regressão — mas é mudança visível, e o dono precisa saber antes de publicar.

### 1.3 Coluna direita — prescrição

Caixa em forma de **aviso** (barra de severidade à esquerda, fundo em tint), não de cartão de
relatório. Uma frase que resolve a leitura sozinha, uma linha de detalhe, e nada mais.

> **Revisto em 2026-07-27, a pedido do dono:** a primeira entrega tinha rótulo `PRESCRIÇÃO` em
> caixa-alta, o número em 1,4rem, três frases de detalhe e duas linhas de ressalva — *"não precisa
> de muita explicação"*. Ficaram: `Risco de prescrição em N dias` (era `Faltam N dias` — a frase
> agora diz o **que** está em jogo, não só o prazo), uma linha de detalhe e a ressalva em uma linha.
> Saiu do detalhe a derivação `Prazo de 5 anos → limite em …`: o gestor quer a **data limite**, não
> a conta que a produziu. `CabecalhoObjetoShowTest::testCaixaDePrescricao` trava a frase nova.

- **Competência mais antiga** = obrigação **em aberto** com o `vencimentoOriginal` mais antigo.
- **Prazo limite** = vencimento + `PRAZO_PADRAO_ANOS` (5), constante única em `CalculadoraPrescricao`.
  Fica pronta para virar configuração da carteira depois; hoje não vale uma migração.
- **Severidade por faixa de dias restantes:** **< 0 esgotado** · ≤ 90 crítico · ≤ 180 atenção · acima
  disso informativo.
  > Corrigido em 2026-07-27 (Etapa 8): a redação original dizia `≤ 0 esgotado`, o que poria o **dia
  > exato do prazo** na faixa "esgotado". No dia do prazo ainda dá para ajuizar, e dizer
  > `Prazo esgotado em <hoje>` faria o gestor abandonar uma cobrança ainda viável — o erro mais caro
  > que esta caixa pode cometer. O código sempre implementou `< 0`; o texto é que estava errado.
  > `CalculadoraPrescricaoTest::faixas` trava o dia zero como **crítica**.
- Quando o prazo já passou: `Prazo esgotado em dd/mm/aaaa`.
- **Sem obrigação em aberto → a caixa não aparece.** Não há o que prescrever.
- **A ressalva de estimativa SAIU** (2026-07-27, decisão do dono). Ela dizia *"Estimativa — não
  considera interrupção nem suspensão do prazo"* e já tinha sido encurtada de duas linhas para uma na
  mesma data; agora não é mais exibida.
  > O cálculo **não mudou**: `CalculadoraPrescricao` continua contando dias a partir do vencimento
  > mais antigo, sem conhecer interrupção nem suspensão — a nota da classe segue valendo, e a caixa
  > continua sendo indicação de risco, não parecer. O que a spec exigia era a ressalva **na tela**, e
  > essa exigência foi revogada pelo dono. Se um dia ela precisar voltar sem ocupar linha, o lugar é o
  > tooltip da frase de destaque. `CabecalhoObjetoShowTest::testCaixaDePrescricao` trava a ausência,
  > para o texto não voltar por acidente.
- `Ver competência` abre a aba Dívida (mesmo mecanismo `data-abrir-aba` que os alertas já usam).

O relógio é o `EncargosVivos::agora()` — o mesmo que rege o resto da página. Nada de `new
\DateTimeImmutable()` no caminho.

### 1.4 Coluna direita — ações

`Registrar contato` · `Simular acordo` · `Planilha atualizada` · `Judicializar` · `Encerrar cobrança`.

- `Simular acordo` e `Planilha atualizada` **não existem** no sistema: entram desabilitados, com
  tooltip dizendo que ainda não têm função. O dono decide depois o que fazem.
- `Judicializar` e `Encerrar cobrança` mantêm gate, modal e regra de hoje — inclusive o botão
  desabilitado que ensina por que não dá para encerrar.
- Os **três pontinhos da maquete saem**: não há o que colocar neles.

### 1.5 Navegação entre unidades

Setas `‹ ›` para a unidade anterior/próxima **da mesma carteira**, ordenadas por
`identificacao ASC, id ASC`.

**Lugar:** **acima do painel do cabeçalho**, em linha própria (`.cob-cab-nav-linha`), encostadas à
direita — decidido pelo dono em 2026-07-27. É o terceiro endereço delas: nasceram ao lado do título,
passaram para a direita da caixa de prescrição e vieram para fora da faixa, onde não disputam atenção
com o dinheiro nem com a prescrição. `CabecalhoObjetoShowTest::testSetasDeNavegacaoEntreUnidades`
trava as três pontas: estão na linha própria, **não** estão dentro de `.cob-cab-painel` e **não**
estão na coluna da identidade.

> Deliberadamente **não** uso a ordem da listagem da carteira (`atualizadoEm DESC`): ela muda sozinha
> a cada registro, e a mesma seta levaria a lugares diferentes a cada visita.

Nas pontas, a seta fica desabilitada. Consulta tenant-safe, limitada a duas linhas (uma para cada
lado) — não carrega a carteira inteira.

---

## 2. Aba Responsáveis

Duas colunas: conteúdo à esquerda (≈2/3), painel de qualificação à direita (≈1/3).

### 2.1 Cabeçalho da aba

`Responsáveis (n)` mais o badge **`Qualificação incompleta`**, que aparece quando a pessoa cobrada não
tem CPF/CNPJ, **ou** não tem estado civil, **ou** não tem nenhum endereço cadastrado. O badge leva à
ficha, onde se corrige.

Ações: `Trocar responsável` · `Editar` (ficha) · `Encerrar vínculo` — todas já existem, com os gates
de hoje.

### 2.2 Card da pessoa cobrada

Avatar de iniciais, nome, `papel · desde mm/aaaa`, e o selo de vínculo encerrado quando for o caso.

### 2.3 Lista de telefones

Vem da **ficha da pessoa** (`PessoaFichaOutput.telefones`), não mais do telefone único derivado. Cada
linha traz o número, o selo `Atual` quando for o marcado, a data de cadastro, e o botão
`Marcar como atual` (rota que já existe). Abaixo, o mini-form de adicionar telefone, inline.

> **O que a maquete pede e não dá para fazer com honestidade:** os selos `WhatsApp 21/07` e
> `Não atende` por telefone. O contato registrado **não guarda a qual telefone se referia** — só canal
> e desfecho, no payload do evento. Exibir esses selos exigiria inventar o vínculo. Ligar
> contato↔telefone é frente própria, com migração. Ficam de fora, e a linha mostra o que é verdade.
>
> Os ícones de **editar** e **excluir** telefone da maquete também não têm rota: entram desabilitados,
> como os botões do cabeçalho.

### 2.4 Faixa de qualificação

`CPF` · `E-mail` · `Estado civil` · `Endereço`, com dado real da ficha; `não informado` quando vazio.
O e-mail e o endereço são os itens marcados como **atual** nas respectivas listas.

### 2.5 Outras pessoas vinculadas

O accordion de hoje, preservado inteiro (contatos, `Definir como atual`, `Editar`,
`Encerrar vínculo`), mais o `Adicionar pessoa`. A maquete mostra um campo de busca solto; o accordion
já resolve melhor e mantém as ações que existem.

### 2.6 Rodapé

`← Voltar` (para a carteira) e `Próxima unidade →`.

> O `Salvar e Seguir` da maquete **não tem o que salvar**: tudo nesta aba grava na hora. O botão vira
> a navegação que ele de fato queria dizer, reusando a ordem das setas do cabeçalho. Na última unidade
> da carteira ele fica desabilitado.

---

## 3. Qualificação de Contato

Painel na coluna direita da aba Responsáveis. Três botões que **gravam num clique, sem modal**.

### 3.1 Modelo

- **Enum novo `QualificacaoContato`**: `recusa_pagamento` · `telefone_inexistente` ·
  `promessa_pagamento`.
- **Tipo de evento novo** `TipoEventoHistorico::QualificacaoContato = 'qualificacao_contato'`.
  `ehTrabalhoDeCobranca()` retorna **`true`** — qualificar devedor é trabalho de cobrança e deve
  contar na Central.
- **Sem migração.** O tipo é coluna de texto (`enumType` sobre string) e a qualificação vai no campo
  `dados` (JSON) que já existe em `cobranca_evento_historico`.

### 3.2 Escopo

O evento pertence **ao caso**, não à pessoa (decisão do dono em 2026-07-27). A listinha do painel é a
da cobrança inteira.

### 3.3 Registro

`RegistrarQualificacaoContatoUseCase`:

- recusa caso encerrado (mesma exceção que anotação e contato usam);
- grava `tipo`, `descricao` = rótulo da qualificação, `dados = {qualificacao: <value>}`, `usuario`,
  `ocorridoEm` — todo o metadado que o dono pediu;
- o evento aparece no Histórico normal, junto dos demais.

> **O gate `resources.cobranca.gerenciar` é do CONTROLLER, não do UseCase** (esclarecido em 2026-07-27,
> Etapa 8; a redação original o atribuía ao UseCase). É o padrão do projeto: o UseCase não conhece
> request nem sessão, e quem sabe *quem* está pedindo é a camada HTTP. Consequência a assumir: um
> segundo chamador (um command, uma API) nasceria **sem gate** — quem criar um tem de repetir a
> checagem. `QualificacaoContatoControllerTest::testRegistrarExigeCapacidadeDeGerenciar` é a prova.

### 3.4 Listinha

Abaixo dos botões, só as qualificações, na ordem mais recente primeiro:
`Recusa de pagamento · 21/07/2026 · Farlei`. Nada além disso.

### 3.5 Desfazer

O histórico é **append-only** por decisão de projeto, então um clique errado seria permanente. Contra
isso:

`DesfazerQualificacaoContatoUseCase` remove o evento quando **as quatro** condições valem:

1. é do tipo `qualificacao_contato`;
2. foi registrado pelo **usuário que está desfazendo**;
3. tem **no máximo 5 minutos** (`JANELA_DESFAZER_MINUTOS`, medido pelo mesmo relógio da tela);
4. é a **qualificação mais recente** do caso.

Fora disso, a operação é recusada com mensagem — não some em silêncio. O botão `desfazer` só é
renderizado para a entrada que satisfaz as quatro condições, decidido **no servidor**; o template não
recalcula prazo nem compara autoria.

Rotas, ambas POST com CSRF e resolução tenant-safe antes de qualquer validação de formulário (anti-IDOR
responde 404 mesmo com token ruim, igual ao que a edição de anotação já faz):

- `POST /cobrancas/casos/{id}/qualificacoes` → `cobranca_qualificacao_registrar`
- `POST /cobrancas/qualificacoes/{eventoId}/desfazer` → `cobranca_qualificacao_desfazer`

---

## 4. O que a leitura da página passa a carregar

`MontarDetalheObjetoUseCase` ganha:

- **`fichaCobrada`** — `PessoaFichaOutput` da pessoa cobrada atual (uma só, não de todos os vínculos),
  com telefones, e-mails e endereços. Custo: um punhado de consultas por página, só da cobrada.
- **`objetoAnteriorId` / `objetoProximoId`** — dos vizinhos na carteira.

`CasoDetalheOutput` ganha os quatro totais do cabeçalho, a contagem de obrigações em aberto, o
`PrescricaoOutput` e a lista de `QualificacaoContatoOutput`.

---

## 5. Testes

**Unitários**

- `RegistrarQualificacaoContatoUseCase`: grava tipo, rótulo, payload e autor; recusa caso encerrado.
- `DesfazerQualificacaoContatoUseCase`: apaga dentro da janela; recusa fora dos 5 min; recusa outro
  usuário; recusa quando não é a mais recente; recusa evento de outro tipo.
- `CalculadoraPrescricao`: faixas de severidade, prazo esgotado, ausência de obrigação em aberto,
  escolha da obrigação mais antiga entre várias.
- Totais do cabeçalho: somam sobre o mesmo conjunto da aba Dívida e ignoram obrigação quitada.

**Funcionais**

- Registrar qualificação: happy path, CSRF inválido, caso de **outro tenant → 404**, caso encerrado.
- Desfazer: happy path e as quatro recusas, mais o cross-tenant.
- Navegação: primeiro e último objeto da carteira têm a seta correspondente desabilitada.
- Aba Responsáveis renderiza a lista de telefones da ficha e a faixa de qualificação.

**Regra de ouro do projeto:** todo teste novo precisa ser provado — reintroduzir o defeito tem de
derrubá-lo. Teste que passa dos dois lados não prova nada.

---

## 6. O que fica de fora, e por quê

| Item da maquete | Motivo |
|---|---|
| `Matrícula` na linha meta | Decisão do dono: não precisa |
| Selos `WhatsApp 21/07` / `Não atende` por telefone | Contato não guarda a qual telefone se referia |
| Editar/excluir telefone | Não há rota; entram desabilitados |
| `Simular acordo`, `Planilha atualizada` | Não existem; entram desabilitados |
| Três pontinhos `⋯` | Não há o que colocar neles |
| `Salvar e Seguir` | Nada a salvar; vira `Próxima unidade →` |
| Prescrição com marcos de interrupção/suspensão | Frente jurídica própria; aqui só a contagem simples |
