# Spec: esquecimento nunca trava o ponto

## Estado da frente (23/10/2026)

- Origem: decisão do dono (23/10), depois do #317. O texto da decisão: "Esquecimento NUNCA trava. Se a Ana esqueceu entrada ou saída
  no escritório A (hoje ou em dia anterior), ela registra normalmente no B. No A fica uma PENDÊNCIA, e ela é orientada a
  enviar o registro de esquecimento. Esquecimento anterior, no A ou no B, não pode impedir registro de ponto."
- Na mesma decisão, o dono aprovou a regra do #317: saída e ida ao repouso passam livres só quando fecham um ponto aberto no
  mesmo escritório. Essa regra já está no master e não muda aqui.
- Risco **ALTO** (ponto eletrônico). Fluxo: spec, implementação, revisão read-only, correção e nova revisão.
- Branch `ponto-esquecimento-nao-trava`. Sem migration, salvo se a implementação provar que precisa. Nesse caso, o número
  é pedido ao coordenador.

## 1. Antes: o que um esquecimento trava hoje

1. **`EntradaAbertaEmOutroEscritorio`**: só na entrada, com janela do mesmo dia. Uma saída esquecida hoje no A, ou um
   repouso sem retorno, recusa a entrada no B até a meia-noite. O pedido de esquecimento pendente não solta a trava (P-A da
   spec `ponto-por-escritorio.md`). Só a aprovação solta.
2. **Atalho do cabeçalho** (`ObterEstadoDoPontoDoDiaUseCase`, estado `regularizar_ontem`): quando ontem, neste escritório,
   ficou uma entrada sem saída e sem pedido, o atalho não oferece "Bater ponto" e leva ao modal de esquecimento. A página
   /ponto continua batendo normalmente.
3. **Interjornada** (`PontoController`, só na entrada): mede o intervalo desde a última saída neste escritório. A aprovação
   de um esquecimento grava a saída no horário informado, e isso pode passar a recusar a entrada seguinte.
4. **Não travam por esquecimento:** a chave do ponto, o choque de jornada (só lê a jornada), o repouso mínimo (só no
   retorno, e só quando houve repouso hoje), a cerca e o turno aberto (só libera).
5. **O pedido de esquecimento** (`JustificativaPonto` tipo `esquecimento_registro`) é criado no escritório em que a pessoa
   está. Não há aviso de pendência de dias anteriores nem de outro escritório. A folha já marca "Registro incompleto" no dia
   e não credita nem debita esse dia.

## 2. Regra

**R1. Nenhum esquecimento recusa batida.** Isso vale para entrada ou saída esquecida, hoje ou em dia anterior, no mesmo
escritório ou em outro.

**R2. A trava de entrada aberta em outro escritório deixa de recusar.**
- Se houver ponto aberto no A (entrada ou retorno sem fechamento), a entrada no B **passa**.
- A resposta da batida traz um **aviso de pendência**: "Ficou um registro em aberto em {escritório} no dia {dd/mm}. Envie o
  registro de esquecimento por lá."
- O escritório é da própria pessoa, então o nome pode aparecer. A regra de não mostrar o nome vale para o choque visto pelo
  admin, não para a própria pessoa.
- O choque de **jornada** continua valendo (#317). Ele é sobre o horário cadastrado e não sobre esquecimento.

**R3. O atalho do cabeçalho sempre oferece "Bater ponto".**
- O estado `regularizar_ontem` continua existindo, mas como **aviso**: mostra a pendência e o caminho do registro de
  esquecimento, sem tirar a batida.
- O desenho do Expediente mostra "Regularizar ponto de ontem" (`03 - EXPEDIENTE`, L677/681). A linha continua; só deixa de
  substituir a batida.

**R4. Interjornada não recusa por saída vinda de esquecimento.**
- Se a última saída usada na medida foi criada pela aprovação de um registro de esquecimento, a interjornada não recusa:
  vira aviso, como em R2.
- Quando a saída foi batida normalmente, a interjornada continua como hoje.

**R5. Pendências visíveis na tela do ponto.** A página /ponto mostra uma lista de pendências da pessoa:
- dias com registro incompleto, neste escritório e nos outros onde ela tem vínculo ativo;
- janela dos últimos 30 dias;
- em cada uma: dia, escritório e o que falta (entrada ou saída);
- o caminho para enviar o registro de esquecimento:
  - no escritório atual, o link já abre o modal (`?esquecimento=AAAA-MM-DD:tipo`);
  - em outro escritório, a orientação é trocar para ele e enviar por lá. O pedido continua sendo criado no escritório a
    que pertence (multi-tenant).
- Pendência com pedido já feito (pendente ou abonado) não aparece.

**R6. Aviso ao bater.** A resposta da batida pode trazer `avisos` (lista de textos com link) além de `message`. A tela
(`#batida-aviso` em /ponto e o aviso do cabeçalho) mostra o aviso **depois** do sucesso, sem tratar como erro.

## 3. O que não muda

- A chave do ponto, o choque de jornada, a cerca, o repouso mínimo e a regra de saída e ida ao repouso do #317.
- A interjornada por saída batida normalmente.
- Quem aprova o esquecimento: o admin do escritório. A aprovação continua gravando a batida.
- A folha continua marcando o dia incompleto até a aprovação.

## 4. Testes

- **Com dois escritórios, A e B:**
  - entrada no A hoje e sem saída: a entrada no B passa e a resposta traz o aviso com o nome do A;
  - repouso sem retorno no A: idem;
  - entrada esquecida no A em dia anterior: B passa sem aviso de trava, e a pendência aparece na lista.
- **Cabeçalho:** com saída de ontem esquecida, o estado oferece a batida e mostra o aviso de regularizar.
- **Interjornada:**
  - uma saída aprovada por esquecimento há 3 horas não recusa a entrada; a resposta traz o aviso;
  - uma saída batida há 3 horas continua recusando.
- **Lista de pendências:**
  - mostra as deste escritório e as dos outros;
  - some quando há pedido pendente ou abonado;
  - não mostra escritório onde a pessoa não tem vínculo ativo;
  - respeita a janela de 30 dias.
- **Multi-tenant:** o pedido de esquecimento continua sendo criado só no escritório corrente.
- A spec `ponto-por-escritorio.md` (P-A) é atualizada para apontar para esta spec.

## 5. Desenho

O desenho só traz o "Regularizar ponto de ontem" do Expediente. Para a lista de pendências e o aviso depois da batida vale a
convenção da tela do ponto: o alerta já usado em `#batida-aviso` e os componentes da própria página. Textos novos de tela
entram pelo catálogo, e as mensagens da batida seguem o padrão das constantes do PHP já existentes.
