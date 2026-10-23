# Spec: jornada e ponto por escritório (Segurança B, PR 2)

## Estado da frente (23/10/2026)

- **Origem.** Os achados de auditoria interna F8 e F11, que são o mesmo defeito: a jornada pessoal vale para todos os
  escritórios da pessoa. O escopo foi ampliado pelo dono em 23/10, nos itens 1 a 4 abaixo.
- **Risco ALTO** (ponto eletrônico). O fluxo é spec, implementação, revisão read-only, correção e nova revisão.
- **Migration `Version20261020150700`,** reservada pelo coordenador.
- **Branch `seguranca-b-jornada`.** O PR 1 (`seguranca-b-ponto`) cobre F2, F12, F18, F19 e F17 e é independente
  deste.

## 1. Antes

- `JornadaColaborador` é OneToOne com `User` e não tem escritório. Por isso o admin de um escritório lê, grava e
  apaga a jornada que vale em todos os escritórios da pessoa. Qualquer usuário pode criar um escritório e passar nos
  guards sobre a própria jornada.
- **Quem lê a jornada sem escritório:** `JornadaResolver` (que monta a cascata colaborador → escritório → campos
  legados), `FolhaPontoBuilder`, `PontoController`, `ObterEstadoDoPontoDoDiaUseCase` e `TenantController`.
- **"Bate ponto" não é escolha de ninguém.** É só um rótulo tirado do tipo de vínculo (`VinculoColaborador::batePonto()`,
  verdadeiro para CLT e estágio). Quem pode bater ponto é decidido só pela permissão do módulo `ponto`.
- **A batida** (`PontoController::batida`) já tem uma trava parecida: `EntradaAbertaEmOutroEscritorio`, na spec
  `ponto-por-escritorio.md` §2.2.

## 2. Regras do dono (23/10)

1. **Jornada por escritório.**
   - Um escritório nunca altera a jornada do outro.
   - Em cada escritório vale a jornada padrão dele (`JornadaTenant`) e, por cima dela, a jornada pessoal do
     colaborador **naquele** escritório.
2. **Chave real "Registra ponto eletrônico"**, por pessoa e por escritório.
   - Com a chave desligada, a pessoa não bate ponto naquele escritório, mesmo tendo a permissão do módulo. A recusa sai
     na batida, com mensagem.
3. **Sem choque de horário entre escritórios.**
   - **Quando se confere:** ao ligar o ponto da pessoa e ao salvar uma jornada. Vale a jornada pessoal e também a
     padrão do escritório, que se aplica a todas as pessoas dele com o ponto ligado.
   - **Como se confere:** se algum intervalo de trabalho, no mesmo dia da semana, se sobrepõe ao de outro escritório
     onde a mesma pessoa registra ponto, a gravação é recusada.
   - **A mensagem** é "Esta pessoa já registra ponto das HH:MM às HH:MM em outro escritório", **sem o nome do
     escritório**.
   - **Quem marcou primeiro fica com o horário.** O escritório que já tem o horário só perde por ação própria. Ampliar
     um horário que passe a bater com o outro também é recusado.
   - **Jornada padrão:** a recusa lista as pessoas afetadas, que são deste escritório, cada uma com a mensagem acima.
   - **Na batida:** recusa o ponto se o momento cai dentro do horário de trabalho de hoje de outro escritório onde a
     pessoa registra ponto.
4. **Backfill** (produção tem 10 jornadas):
   - pessoa com um único escritório ativo: a jornada vai para ele;
   - pessoa sem vínculo ativo: vai para o último escritório. A fonte é o último `RegistroPonto`, porque remover o
     colaborador apaga o `user_tenant`.
   - pessoa com vários vínculos ativos: vai só para o escritório onde ela bateu ponto por último. Nos outros, a chave
     "Registra ponto eletrônico" fica **desligada**.

## 3. Depois

### 3.1 Modelo (migration 150700)

- **`jornada_colaborador`:**
  - ganha `tenant_id`, que entra nulo, recebe o backfill e depois vira NOT NULL;
  - o índice único passa a `(user_id, tenant_id)`;
  - a entidade fica TenantAware e com ManyToOne `tenant`;
  - sai o OneToOne do `User`;
  - o repositório ganha `findByUserETenant`.
- **`vinculo_no_escritorio`:** ganha `registra_ponto` (boolean NOT NULL), o lugar natural por ser único por pessoa e
  escritório.
  - **Padrão ao criar um vínculo novo:** o valor de `VinculoColaborador::batePonto()` do tipo escolhido, para manter o
    comportamento de hoje.
  - **Backfill:** `batePonto()` do tipo atual, menos nos casos da regra 4, em que fica desligado nos escritórios que
    não ficaram com a jornada.
  - **A pessoa com permissão do módulo `ponto` e sem linha em `vinculo_no_escritorio`:** ver §5.
- O rótulo do Meu Perfil passa a ler a chave e não o tipo.

### 3.2 Leitura da jornada

- Toda leitura usa o escritório da folha, da URL ou da batida (`findByUserETenant`). A cascata do `JornadaResolver`
  continua: pessoal do escritório → padrão do escritório → campos legados.
- Um serviço novo, `IntervalosDeTrabalho`, devolve, por dia da semana, os intervalos (entrada, saída e repouso) da
  jornada efetiva de uma pessoa num escritório. Serve para o choque e para a batida.

### 3.3 Choque (`ChoqueDeJornadaEntreEscritorios`)

- Compara os intervalos efetivos da pessoa no escritório A, já com a mudança proposta, com os de cada outro escritório
  B onde `registra_ponto = true`. Se há sobreposição, devolve o primeiro intervalo de B que bate, para a mensagem.
- **Onde é chamado:**
  - ao ligar a chave;
  - ao salvar ou apagar a jornada pessoal (apagar faz valer a padrão, que também é conferida);
  - ao salvar a jornada padrão do escritório, para todas as pessoas dele com o ponto ligado;
  - na batida, ao lado da trava `EntradaAbertaEmOutroEscritorio`, com a hora atual contra os intervalos de hoje dos
    outros escritórios.
- **Mensagens** no padrão da trava existente, como constantes de texto no PHP devolvidas no JSON de erro. Sem nome de
  escritório.

### 3.4 Tela

- O desenho não tem a chave nem a tela de jornada (os de Meu Perfil e Perfis 1.2 só têm o rótulo), então vale a
  convenção do sistema.
- A chave "Registra ponto eletrônico" fica na página do colaborador no escritório (`edit_user_role`), na aba de
  jornada. Ela tem um interruptor com guarda de `admin.users.manage` e vínculo ativo, como a jornada.
- Os erros de choque aparecem onde o salvar já mostra erros. Textos novos de tela entram pelo catálogo.

### 3.5 Segurança e guarda

- Os guards atuais (`admin.users.manage` mais o vínculo ativo da pessoa no escritório da URL) passam a casar com o
  recurso, porque a jornada e a chave são do escritório da URL.
- O cenário do F8 deixa de valer: quem cria um escritório só mexe na própria jornada e na chave **daquele** escritório,
  e o choque protege o horário do outro.

## 4. Testes

- **Dois escritórios, A e B:**
  - o admin de A grava a jornada de E em A, e a de E em B fica intacta;
  - a GET em B não mostra a de A.
- **F8:** o funcionário cria o escritório C, liga o ponto e grava uma jornada que bate com a de A; é recusado com a
  mensagem, sem o nome de A. Uma jornada que não bate é aceita, e a folha de A não muda.
- **Choque:**
  - ao ligar a chave;
  - ao salvar a jornada pessoal;
  - ao salvar a padrão (com a lista de pessoas);
  - ao apagar a pessoal, quando a padrão bate;
  - ao ampliar o horário de quem chegou primeiro: também recusa.
- **Batida:**
  - dentro do horário do outro escritório: recusa;
  - fora: aceita;
  - com a chave desligada: recusa.
- **`JornadaResolver` e `FolhaPontoBuilder`:** a meta do dia em B usa a jornada de B.
- **Backfill (integração):**
  - os três casos da regra 4;
  - a chave desligada nos escritórios que não ficaram com a jornada;
  - migration aplica e reverte, e `schema:validate` fica limpo.

## 5. Para o dono decidir antes da implementação

1. O padrão da chave para vínculo novo é `batePonto()` do tipo (CLT e estágio ligados), mantendo o de hoje. Ou nasce
   sempre desligada?
2. A pessoa com permissão do módulo `ponto` e sem linha em `vinculo_no_escritorio` é tratada como chave ligada
   (comportamento de hoje) ou desligada?
