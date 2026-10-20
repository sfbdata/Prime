# Spec: prazos lidos dos registros com o calendário do sistema

## Estado da frente (atualizado em 20/10/2026)

- Branch `prazo-feriado-calendario`, a partir do master (a0af62f9). Risco **MÉDIO** (muda a data
  estimada de prazos gravados em pasta real). Pedido do coordenador em 19/10, a partir do inventário do
  desenho 09 (Agenda), L97: "prazos que vencem em feriado passam para o próximo dia útil".
- **Para o dono** (coordenador leva ao pacote): definir `PRAZOS_CALENDARIO_DESDE` no deploy; contar ou não
  Carnaval e Corpus Christi como dia não útil.
- **Sem migration.** Uma variável de ambiente nova (`PRAZOS_CALENDARIO_DESDE`), definida pelo dono no
  deploy.

## 1. Antes

- `App\Pasta\Service\LeitorDeDatas` lê os registros da pasta (expedientes, Detalhes, Financeiro) e grava
  os prazos em `pasta_prazo` (`origem = registro`) pelo `PrazosDosRegistros`, a cada registro salvo
  (listener) e pelo comando `app:pastas:ler-prazos-dos-registros`.
- "prazo de N dias (úteis)" vira data **estimada** contando dias úteis; "N dias corridos" conta corrido.
- Dia útil = não é sábado nem domingo, não está numa lista própria de **9 feriados fixos** (01/01, 21/04,
  01/05, 07/09, 12/10, 02/11, 15/11, 20/11, 25/12) e não está no recesso (20/12 a 20/01).
- Faltam: Sexta-feira Santa (móvel), feriados estaduais. O prazo corrido que termina num feriado ou fim de
  semana fica nesse dia.
- A Agenda (desenho 09) já tem o `CalendarioDoSistema` (#54), com nacionais (inclusive móveis), estaduais
  por UF, tribunais, recesso e pontos facultativos, mas o leitor não o usa.

## 2. Depois

- O leitor passa a perguntar ao `CalendarioDoSistema` se um dia é útil, para a UF do escritório da pasta
  (`Tenant::estado`; a lotação do usuário não existe no listener nem no comando).
- **Não é dia útil** (conta como não útil): sábado, domingo, feriado nacional do tipo `feriado` (inclui
  Sexta-feira Santa), feriado estadual da UF do escritório e o recesso (CPC art. 220).
- **Continua útil** (decisão conservadora, para a estimativa nunca passar do prazo real): ponto
  facultativo (Carnaval, Corpus Christi), dias de tribunal "confira o tribunal", eleições, comemorativas e
  os feriados do Ponto do escritório. Contar esses dias empurraria a estimativa para depois, e um prazo
  estimado tarde demais é o erro grave; cedo demais só pede conferência ("estimado: conferir").
- **Prazo em dias corridos** que termina em dia não útil passa para o próximo dia útil (CPC art. 224 §1º;
  é a frase do desenho L97). Em dias úteis isso já acontece pela própria contagem.
- **Data escrita pelo advogado** ("prazo até 02/11", audiência em 15/11): **não muda**. O leitor só
  ajusta a data que ele mesmo estima. Audiência nunca é movida.
- Ajustes do escritório na Agenda (`agenda_data_ajuste`, PR #70 da 4b, fora do master) **não entram** nesta
  fatia; quando o #70 estiver no master, decidir se "Acrescentar feriado" conta no prazo (registrado).

## 3. Prazos já gravados: não mudam

O padrão do coordenador vale: **não mexer em prazo já calculado; só os novos**.
- A releitura é automática (qualquer registro salvo relê a pasta inteira), então uma troca simples do
  cálculo mudaria prazos antigos. Para evitar, a regra nova só vale para registros criados **a partir de
  `PRAZOS_CALENDARIO_DESDE`** (data ISO, variável de ambiente). Registro mais antigo continua com o cálculo
  antigo, mesmo relido.
- Vazia (padrão), a regra nova fica **desligada** e nada muda. O dono define a data no deploy: **o dia
  SEGUINTE ao deploy** (revisão de 20/10: com o próprio dia do deploy, os registros feitos nesse dia antes do
  deploy foram gravados pelo cálculo antigo e mudariam na releitura). Data no passado tem o mesmo efeito.
  Valor inválido desliga e avisa no log.
- **Alcance da garantia** (revisão de 20/10): "nenhum prazo gravado muda" vale para os registros **anteriores
  ao marco**. Os posteriores são relidos com o calendário e a UF do momento: mudar o `CalendarioDoSistema`,
  a regra de Carnaval e Corpus Christi, os ajustes da Agenda (#70) ou a UF do escritório move a estimativa
  deles na próxima releitura (sem perda de dado: prazo lido não pode ser cumprido). **Para o dono**: qualquer
  mudança futura na regra (ex.: contar o Carnaval) precisa de um marco próprio se não puder mexer nos já
  estimados.

## 4. Casos (base: registro criado depois do marco, escritório em AL)

| Texto | Antes | Depois |
|---|---|---|
| "prazo de 5 dias" em 30/03/2026 (Sexta Santa 03/04) | 06/04 (contava 03/04) | 07/04 |
| "prazo de 3 dias" em 13/02/2026 (Carnaval 16 e 17/02) | 18/02 | 18/02 (ponto facultativo continua útil) |
| "prazo de 3 dias" em 02/06/2026 (Corpus Christi 04/06) | 05/06 | 05/06 (ponto facultativo) |
| "prazo de 2 dias" em 15/09/2026 (Emancipação de AL, 16/09) | 17/09 | 18/09 |
| "prazo de 10 dias corridos" em 08/04/2026 (cai em 18/04, sábado) | 18/04 | 20/04 |
| "prazo de 5 dias" em 18/12/2026 (recesso de 20/12 a 20/01) | 27/01/2027 | 27/01/2027 (igual) |
| "prazo até 03/04/2026" (data escrita) | 03/04 | 03/04 (não muda) |
| escritório em MG (sem estadual no calendário) | igual ao nacional | igual ao nacional |
| registro criado antes do marco | antigo | antigo (não muda) |

(Datas exatas conferidas pelos testes; esta tabela é a intenção.)

## 5. Fora

- `pasta-metas.js` (`somarUteis` do front, atalho de prazo de meta) não conhece feriado; continua igual
  nesta fatia: **divergência conhecida** entre o prazo de meta (front) e o prazo lido (servidor), registrada
  pelo coordenador em 20/10 como melhoria separada.
- Feriados municipais e do Ponto, dias de tribunal, ajustes da Agenda: decisão futura (dono).

## 6. Verificação

- Unit do leitor: cada linha da tabela §4, marco vazio = cálculo antigo, marco antes/depois do registro,
  UF sem estadual, recesso igual.
- Functional do `PrazosDosRegistros`: registro antigo relido não muda a data gravada; registro novo
  grava a data nova; o `PastaPrazo` antigo não é apagado/recriado quando nada muda.
- `LeitorDeDatasTest` atual continua verde com o marco vazio.
