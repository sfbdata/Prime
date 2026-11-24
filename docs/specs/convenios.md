# Convênios do BlueJus: desligar e cadastrar convênios

Spec do pedido do Farlei de 24/11/2026 (via Samuel), sobre /master > Planos e limites:

> "deveria ter o modo de desativar convênio, geral ou por estado ou por instituição, sugestão que fosse discreto,
> evitar poluição visual, considerando que poderia ter funcionalidade de adicionar novos convênios."

**Aprovado pelo Samuel em 24/11:** as tabelas novas `convenio` e `convenio_vinculo` (§3) e as 13 decisões do §12
(D1 a D13), todas pela recomendação; D3 = não cobrar.

**Risco: MÉDIO** no conjunto (mexe no Meu Perfil e no cadastro). O PR 8 (desconto no valor cobrado) é tratado como
**ALTO**: re-revisão antes de seguir.

**Convenção:** todo fato de código cita arquivo:linha conferido no master de 24/11 (949d558b2), caminhos a partir de
`app/`. O que ainda não existe está marcado como **proposta**. Cada regra termina com **Recomendação**.

## 1. Como está hoje

- **Convênio OAB por estado:** tabela `convenio_uf` (src/Master/Entity/ConvenioDaUf.php:24-27, migration
  `Version20261026151800`). PK `uf`; `modo` `dif` (valor diferenciado) ou `sem` (sem convênio, mensalidade cheia);
  `valor`, `desde`, `motivo`, `alterado_em`, `alterado_por_id`. **Sem linha = convênio ativo e grátis.** Global,
  sem tenant, auditável.
- **Fluxo:** src/Master/Controller/ConveniosDaOabController.php:29-56 (`master_convenios_dados` e
  `master_convenios_alterar`, CSRF `master_convenios_oab`), src/Master/UseCase/AlterarConvenioDaUfUseCase.php
  (trava por UF em :55, aviso só quando modo ou valor mudam em :77), src/Master/Service/AvisoDeConvenioDaUf.php:41-51
  (e-mail aos primários ativos dos escritórios da UF, origem `convenio_oab`), public/js/master/convenio-oab.js e
  public/css/master-convenio-oab.css. Painel "Convênio OAB por estado" na seção Contas (public/js/master/painel.js:773,
  :793-800).
- **O convênio não muda cobrança.** Só soma na receita estimada (src/Master/Service/ApuracaoDeCustos.php:80, :123-128)
  e dá o rótulo de cada conta pela UF do escritório (src/Master/UseCase/ConsultarContasUseCase.php:88, :151-158;
  painel.js:804-810). O Plano Free é grátis para **todas** as contas, advogado ou não.
- **Textos fixos de convênio:**
  - Master: tag do Free e faixa "Convênio OAB · BlueJus gratuito para advogados"
    (translations/master/messages+intl-icu.pt_BR.yaml:846, :857-859; painel.js:1138, :1143);
  - Contribua: link "Por que é gratuito" (templates/contribua/index.html.twig:36), seção `#convenio` (:67-80), pergunta
    frequente (:87), selo "CONVÊNIO OAB" no pacote gratuito (:168-171), modal `_convenio_oab.html.twig` (:378);
    o selo sai de `'oab' => $p->ehGratuito()` (src/Contribua/UseCase/MontarPaginaDoContribuaUseCase.php:231-232).
  - Cadastro e Meu Perfil: não falam de convênio.
- **Cobrança real:** plano de espaço (src/Contribua/Entity/Plano.php; Free 10 GB grátis, Essencial 19,90/20 GB,
  Profissional 39,90/50 GB, Avançado 69,90/100 GB), só Asaas. Valor nasce em
  src/Contribua/UseCase/IniciarAssinaturaUseCase.php:79 e muda por `GatewayDeAssinatura::alterarValor`
  (src/Contribua/Gateway/GatewayDeAssinatura.php:68), hoje usado só na ampliação automática
  (src/Contribua/UseCase/AmpliarEspacoAutomaticamenteUseCase.php:197, desfazer em :213).
- **Espaço extra já existe** para indicação: `BonusDeEspaco` (src/Contribua/Entity/BonusDeEspaco.php), somado ao
  limite em src/Contribua/Service/SituacaoDoEspaco.php:86. Acima do limite, a faixa de bloqueio pausa envios
  (SituacaoDoEspaco.php:91); nada é apagado.
- **OAB do usuário:** `User.oabNumero/oabUf/oabStatus` e `Auth/Entity/InscricaoOab` (numero, uf, tipo
  principal|suplementar, status `nao_verificada|divergente|confirmada` em src/Auth/Enum/StatusOab.php:17-19,
  `verificadaEm`). Revisão do Master já existe (src/Master/Controller/RevisaoOabDoMasterController.php).
- **Escritório** tem `estado` (texto; a UF sai de `LocalizacaoDaPessoa::uf`), gravado em
  src/Tenant/UseCase/SalvarDadosDoEscritorioUseCase.php:99. Não existe entidade de instituição.
- **Configuração global por prefixo:** `ConfiguracaoGlobal` (src/Master/Entity/ConfiguracaoGlobal.php:33-39, prefixos
  `revisaoOab.`, `comercial.`, `ia.` fora do catálogo).

## 2. Regras que não mudam

1. **Nada é apagado** quando um convênio acaba. Acima da cota, só pausa o envio (como as faixas de hoje).
2. **Ninguém passa para plano pago sem querer.** Fim de benefício nunca cria assinatura nem cobra cartão novo.
3. **Sem convênio cadastrado = como hoje.** A migração não muda o que nenhuma conta vê nem paga.
4. **O Plano Free continua grátis para todas as contas** nesta spec. Cobrar mensalidade de quem fica fora de convênio
   é decisão própria (D3), fora daqui.
5. **Só o Master da plataforma** mexe em convênio (§9). O escritório só digita código de convite.
6. **Toda mudança vai para a Trilha de Auditoria** (entidades `Auditavel`, escopo de plataforma para o convênio).
7. **Sem travessão** em nenhum texto novo; o separador visual é o ponto médio "·", como já usado.

## 3. Modelo de dados (PR 2, a única migration)

### 3.1 Tabela `convenio` (proposta, global, sem tenant)

```sql
CREATE TABLE convenio (
  id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  chave VARCHAR(40) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  instituicao VARCHAR(120) NOT NULL,
  instituicao_chave VARCHAR(40) NOT NULL,
  abrangencia VARCHAR(12) NOT NULL,
  uf VARCHAR(2) DEFAULT NULL,
  prova VARCHAR(10) NOT NULL,
  beneficio VARCHAR(20) NOT NULL,
  beneficio_valor INT NOT NULL DEFAULT 0,
  texto_selo VARCHAR(40) NOT NULL,
  codigo_convite VARCHAR(16) DEFAULT NULL,
  limite_de_usos INT DEFAULT NULL,
  valido_de DATE NOT NULL,
  valido_ate DATE DEFAULT NULL,
  ativo BOOLEAN NOT NULL DEFAULT TRUE,
  encerra_em DATE DEFAULT NULL,
  motivo VARCHAR(200) DEFAULT NULL,
  criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  alterado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  alterado_por_id INT DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_convenio_alterado_por FOREIGN KEY (alterado_por_id) REFERENCES "user" (id) ON DELETE SET NULL,
  CONSTRAINT chk_convenio_abrangencia CHECK (abrangencia IN ('nacional','uf','instituicao')),
  CONSTRAINT chk_convenio_uf CHECK ((abrangencia = 'uf') = (uf IS NOT NULL)),
  CONSTRAINT chk_convenio_prova CHECK (prova IN ('oab','codigo')),
  CONSTRAINT chk_convenio_beneficio CHECK (beneficio IN ('gratuidade','desconto_percentual','desconto_fixo','espaco_extra')),
  CONSTRAINT chk_convenio_valor CHECK (beneficio_valor >= 0 AND (beneficio <> 'desconto_percentual' OR beneficio_valor BETWEEN 1 AND 100)),
  CONSTRAINT chk_convenio_codigo CHECK ((prova = 'codigo') = (codigo_convite IS NOT NULL)),
  CONSTRAINT chk_convenio_validade CHECK (valido_ate IS NULL OR valido_ate >= valido_de)
);
CREATE UNIQUE INDEX uniq_convenio_chave ON convenio (chave);
CREATE UNIQUE INDEX uniq_convenio_codigo ON convenio (codigo_convite) WHERE codigo_convite IS NOT NULL;
CREATE INDEX idx_convenio_instituicao ON convenio (instituicao_chave);
CREATE INDEX idx_convenio_alterado_por ON convenio (alterado_por_id);
```

Campos:

- `chave`: identificador fixo (`oab`, `caa-sp`). `instituicao_chave` agrupa os convênios da mesma instituição para
  "desligar por instituição" (§6.3).
- `abrangencia`: `nacional` (todo o país), `uf` (só escritórios daquela UF), `instituicao` (só quem provar que é membro).
- `prova`: `oab` (inscrição OAB, automática) ou `codigo` (código de convite digitado pelo escritório).
- `beneficio` + `beneficio_valor`: `gratuidade` (0), `desconto_percentual` (1 a 100), `desconto_fixo` (centavos),
  `espaco_extra` (GB). Um benefício por convênio.
- `texto_selo`: o que o cliente vê no selo, até 40 caracteres (ex.: "CONVÊNIO OAB").
- `ativo` + `encerra_em`: desligar com aviso prévio grava `encerra_em`; no dia, `ativo` vira falso (§6).

**Recomendação:** instituição como texto + `instituicao_chave` na própria tabela, **sem** tabela `instituicao`.
Hoje há uma instituição (OAB); uma tabela a mais só se uma instituição tiver dados próprios (contato, contrato).

### 3.2 Tabela `convenio_vinculo` (proposta, por escritório)

```sql
CREATE TABLE convenio_vinculo (
  id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  tenant_id INT NOT NULL,
  convenio_id INT NOT NULL,
  origem VARCHAR(10) NOT NULL,
  inicio TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  fim TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
  fim_previsto DATE DEFAULT NULL,
  motivo_fim VARCHAR(20) DEFAULT NULL,
  aviso_enviado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
  prova_pendente_desde TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
  criado_por_id INT DEFAULT NULL,
  encerrado_por_id INT DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_convenio_vinculo_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
  CONSTRAINT fk_convenio_vinculo_convenio FOREIGN KEY (convenio_id) REFERENCES convenio (id) ON DELETE RESTRICT,
  CONSTRAINT fk_convenio_vinculo_criado_por FOREIGN KEY (criado_por_id) REFERENCES "user" (id) ON DELETE SET NULL,
  CONSTRAINT fk_convenio_vinculo_encerrado_por FOREIGN KEY (encerrado_por_id) REFERENCES "user" (id) ON DELETE SET NULL,
  CONSTRAINT chk_convenio_vinculo_origem CHECK (origem IN ('oab','codigo','master')),
  CONSTRAINT chk_convenio_vinculo_motivo CHECK (motivo_fim IS NULL OR motivo_fim IN
    ('convenio_desligado','uf_desligada','instituicao','prova_divergente','estado_mudou','validade','master','saiu')),
  CONSTRAINT chk_convenio_vinculo_periodo CHECK (fim IS NULL OR fim >= inicio)
);
CREATE UNIQUE INDEX uniq_convenio_vinculo_aberto ON convenio_vinculo (tenant_id, convenio_id) WHERE fim IS NULL;
CREATE INDEX idx_convenio_vinculo_tenant ON convenio_vinculo (tenant_id);
CREATE INDEX idx_convenio_vinculo_convenio_aberto ON convenio_vinculo (convenio_id) WHERE fim IS NULL;
CREATE INDEX idx_convenio_vinculo_criado_por ON convenio_vinculo (criado_por_id);
CREATE INDEX idx_convenio_vinculo_encerrado_por ON convenio_vinculo (encerrado_por_id);
```

- **Histórico:** sair e voltar cria linha nova; a aberta é única por escritório e convênio (índice parcial, mapeado
  com `#[ORM\UniqueConstraint(..., options: ['where' => '(fim IS NULL)'])]`, precedente em
  src/Dashboard/Entity/AcompanhamentoDaPessoa.php:29).
- **Multi-tenancy:** o convênio é global (como `ConvenioDaUf`); o vínculo é do escritório.
  **Recomendação:** `ConvenioVinculo` **não** é `TenantAware`, pelo mesmo motivo do `BonusDeEspaco`
  (BonusDeEspaco.php:20-22): o Master no escritório X lê e encerra o vínculo de Y, e a sincronização roda no worker.
  Só o `ConvenioVinculoRepository` consulta, sempre com `tenant_id = :tenant` escrito à mão; um teste de arquitetura
  (molde `BonusDeEspacoSoNoContribuaArquiteturaTest`) impede consulta fora dele; a exceção entra em
  tests/Shared/Functional/TenantAwareCoberturaTest.php.
- **Exclusão de conta:** `ON DELETE CASCADE` na purga do escritório, como a `contratacao`.

### 3.3 O que acontece com `convenio_uf`

**Recomendação: manter `convenio_uf` como está**, sem mover dado, como a tabela de **exceções por UF do convênio
`oab`**. A migration só insere a linha do convênio OAB:

```sql
INSERT INTO convenio (chave, nome, instituicao, instituicao_chave, abrangencia, prova, beneficio, beneficio_valor,
  texto_selo, valido_de, ativo, criado_em, alterado_em)
VALUES ('oab', 'Convênio OAB', 'OAB', 'oab', 'nacional', 'oab', 'gratuidade', 0,
  'CONVÊNIO OAB', '2026-10-26', TRUE, NOW(), NOW());
```

Por quê: a tabela já faz "desligar por estado" (`modo = sem`), tem aviso por e-mail, trava e testes; mover para uma
tabela nova genérica só serviria a outro convênio nacional com exceção por UF, que não existe. Se um dia existir,
aí entra `convenio_id` em `convenio_uf` (PK passa a `convenio_id, uf`), numa migration própria.

`down()`: `DROP TABLE convenio_vinculo; DROP TABLE convenio;` (a `convenio_uf` não é tocada). Testar o `down()`.
Usar a skill `criar-migration` (`make:migration` e limpeza do lixo de outra frente).

### 3.4 Leitura única: `ConveniosDoEscritorio` (proposta, PR 2)

`Master/Service/ConveniosDoEscritorio::doTenant(int $tenantId, ?string $uf): list<ConvenioVigente>` (e `dosTenants` em lote) é a única porta de leitura para
telas, cobrança e espaço. Decide nesta ordem:

1. chave geral `convenio.ativo` desligada (Etapa 1) → nenhum convênio vale para exibição nem para vínculo novo;
   benefícios reais já vinculados seguem até o Master desligar cada convênio (§6.1);
2. vínculos abertos do escritório (`fim IS NULL`) cujo convênio está `ativo` e dentro da validade;
3. para o convênio `oab`: a exceção da UF do escritório em `convenio_uf` (`sem` = não vale; `dif` = vale, com o valor
   só para a receita estimada, como hoje).

Lote para o painel (uma consulta para todas as contas), como `retratarVarios` (SituacaoDoEspaco.php:62).

**Decisão de implementação (Coordenador, 24/11, pela recomendação).** O PR 2 não muda nada na tela:

- escritório **sem vínculo nenhum gravado** (nem aberto nem fechado): o convênio `oab` vale **implícito**. É o caso
  de todos até o PR 3, que troca o implícito pelos vínculos gravados pela sincronização;
- o `sem` e o `dif` da UF mexem só no rótulo das contas no painel do Master (`sem` tira o `oab` dos vigentes, mas a
  conta segue com o rótulo vermelho "sem convênio" do estado);
- o selo do Contribua segue só a chave geral até o PR 6;
- coberto por teste "mesma saída": o rótulo das contas (`livre`, `dif`, `sem`) sai igual ao de antes; com a chave
  geral desligada, sai nulo (sem rótulo).

A linha `oab` também entra em `scripts/ci-dados-de-referencia.sql`, para o banco montado só com `schema:create`.

Para os próximos PRs (revisão do PR 2, 24/11):

- **Trilha no escritório certo (PR 3 e PR 4).** `ConvenioVinculo` não é `TenantAware` e `Convenio` é global: o
  `AuditLogSubscriber` grava o log com o escritório da sessão. Os UseCases que gravam ligam o `escopoDePlataforma` com
  a `contaAlvo` do vínculo (precedente: `AlterarLiberacaoDoVotoUseCase`), com teste do `tenant_id` da linha da Trilha.
- **Rótulo vermelho depois dos vínculos (PR 3).** Conta com o vínculo OAB fechado vem com `convenio` nulo; se o Master
  marca `sem` na UF dentro da tela, o rótulo só aparece ao recarregar. O PR 3 recarrega a lista de contas ao gravar a
  UF (ou devolve o `sem` também para essas contas).

## 4. Benefício

| Benefício | O que faz | Onde entra |
|---|---|---|
| `gratuidade` | Plano Free sem custo (como hoje) | Só rótulo, selo e receita estimada. Não muda cobrança. |
| `espaco_extra` | GB a mais no limite enquanto o vínculo vale | SituacaoDoEspaco.php:86, somado como o bônus |
| `desconto_percentual` / `desconto_fixo` | Desconto na mensalidade dos planos pagos | IniciarAssinaturaUseCase.php:79, AmpliarEspacoAutomaticamenteUseCase.php:197 e `alterarValor` ao entrar e sair |

Regras:

1. **Vários convênios no mesmo escritório:** benefícios de tipos diferentes convivem; do mesmo tipo vale **o maior**,
   nunca somam. **Recomendação:** assim.
2. **Espaço extra** é lido por um serviço do Contribua (`EspacoDosConvenios`, proposta) que chama
   `ConveniosDoEscritorio`; não grava `BonusDeEspaco` (a coluna `indicacao_id` é obrigatória, BonusDeEspaco.php:47, e
   o bônus tem prazo fixo). **Recomendação:** assim, sem migration no Contribua.
3. **Desconto:** o valor final nunca fica abaixo do mínimo de cobrança do Asaas (conferir na doc do Asaas antes do
   PR 8) nem abaixo de zero. O valor cobrado continua em `AssinaturaDeEspaco.valorCentavos`; a nota fiscal segue o valor
   cobrado. A receita estimada (ApuracaoDeCustos.php:80) passa a usar o valor com desconto.
   - Escritório que entra num convênio com assinatura viva: `alterarValor` vale a partir da próxima cobrança.
   - Escritório que sai: `alterarValor` volta ao preço cheio só depois do aviso prévio (§6.2).
   - Falha no Asaas: nada muda no banco e o Master vê o erro (mesmo molde de AmpliarEspacoAutomaticamenteUseCase.php:213).
4. **Recomendação de ordem:** `gratuidade` e `espaco_extra` primeiro (não mexem em dinheiro); desconto por último, num
   PR próprio (PR 8). Mensalidade para quem está fora de convênio: **não** nesta spec (D3).

## 5. Elegibilidade e prova

### 5.1 OAB (`prova = oab`)

- Vínculo **automático** quando: UF do escritório válida (`LocalizacaoDaPessoa::uf`), a UF não está `sem` em
  `convenio_uf`, e o criador do escritório (`Tenant::getCriadoPor`, como `DonoDoEscritorio::ehDono`,
  src/Backup/Service/DonoDoEscritorio.php:17-24) tem ao menos uma inscrição OAB que **não** está `divergente`.
  Conta do Voto sem OAB (condomínio) não ganha vínculo OAB.
- **Quem confere:** o sistema, em `SincronizarVinculosDeConvenioUseCase` (proposta), idempotente, chamado:
  - por uma tarefa diária do agendador (src/Shared/Scheduler/AgendaDeTarefas.php);
  - logo depois de mudar o estado do escritório (SalvarDadosDoEscritorioUseCase.php:99), do cadastro e de cada
    mudança do Master num convênio ou numa UF.
- A primeira execução da tarefa preenche os vínculos dos escritórios que já existem. **Recomendação:** sem INSERT na
  migration (a UF sai de texto livre, `LocalizacaoDaPessoa` é PHP); a primeira volta do agendador faz.
- **Mudou de estado:** fecha o vínculo com `estado_mudou` e abre o da UF nova, se couber.

**Decisões de implementação do PR 3 (24/11, pela recomendação):**

- **Fim do OAB implícito:** a primeira volta completa da tarefa diária, com a chave geral ligada, grava
  `convenio.vinculosSincronizadosEm` em `ConfiguracaoGlobal`. Daí em diante só vale o vínculo gravado: conta sem
  vínculo OAB (sem OAB válida do criador, sem estado) perde o rótulo verde no painel; a UF `sem` continua com o
  vermelho. Com a chave desligada, nada é criado e a marca não é gravada.
- **Estado:** o convênio OAB é nacional. Mudar de um estado com convênio para outro não fecha o vínculo (fechar e
  reabrir o mesmo convênio não diz nada). Fecha com `estado_mudou` quando o escritório fica sem estado válido ou vai
  para uma UF `sem`; a tarefa diária e a mudança da UF pelo Master fecham com `uf_desligada`. A UF `sem` vale a partir
  da data `desde` dela.
- **Sem OAB válida:** o criador sem inscrição (ou só com divergente) segue o caminho da prova pendente (§5.2): a marca,
  30 dias, depois `prova_divergente`. Escritório em quarentena não ganha vínculo; o aberto segue até a purga.
- **Validade:** vínculo aberto de convênio fora da validade fecha com `validade`; desligado, com `convenio_desligado`
  (o aviso prévio é do PR 4).
- **Ganchos:** mudança de estado nos dados do escritório e mudança da UF pelo Master, depois de gravar, como melhor
  esforço (a tarefa diária corrige). O cadastro não precisa: o escritório nasce sem estado. O painel recarrega a
  lista de contas depois de gravar uma UF.
- **Trilha:** cada escritório numa transação com trava própria; a linha da Trilha fica no escritório do vínculo
  (`executarComoConta`).

### 5.2 OAB divergente

**Recomendação:** não corta na hora. A CNA tem falso negativo e o Master já revisa a OAB.

1. Inscrição do criador vira `divergente` → o vínculo ganha `prova_pendente_desde` e aparece na lista do Master com
   a marca "prova pendente" (link para a Revisão de OAB).
2. Se seguir divergente por **30 dias**, a sincronização fecha o vínculo com `prova_divergente`, com as regras do
   §6.2 (aviso, prazo; nada apagado).
3. Voltou a `confirmada` ou `nao_verificada` antes disso: a marca some.

Para o benefício `gratuidade` isso só muda rótulo; o rigor importa quando houver desconto ou espaço extra.

### 5.3 Outras instituições (`prova = codigo`)

| Forma | Recomendação |
|---|---|
| **Código de convite** | **Sim, a primeira e única desta spec.** O Master gera o código no convênio (8 letras e números, sem 0/O/1/I), com `limite_de_usos` opcional. A instituição repassa aos membros. Sem dado pessoal. |
| Domínio de e-mail | Não agora. Domínio de instituição raramente é o e-mail do advogado, e domínio público (gmail) abriria para todos. |
| Lista enviada pela instituição | Não agora. Traz CPF ou número de OAB de terceiros: base legal e guarda pela LGPD (pacote jurídico, §10). Só se uma instituição exigir, com spec própria. |

- **Quem digita:** um primário do escritório (titular ou sócio, `RegraDosPrimarios`,
  src/Tenant/Service/RegraDosPrimarios.php:25), no Meu Perfil, ou no cadastro por `/cadastro?convenio=CODIGO`
  (src/Auth/Controller/CadastroController.php:55), que guarda o código e o aplica ao criar o escritório.
- **Quem confere:** o sistema (código existe, convênio ativo, dentro da validade, sem `encerra_em`, abaixo do limite
  de usos, e para `abrangencia = uf` a UF do escritório bate). Recusa com mensagem curta, sem dizer qual regra falhou
  além de "Código inválido ou encerrado." O Master pode encerrar o vínculo (`master`).
- Tentativas: limitar a 5 por hora por escritório (o RateLimiter do Symfony), contra adivinhação.

## 6. Desligar

### 6.1 Os quatro jeitos

| Jeito | Onde | Efeito em quem já tem | Efeito em cadastro novo |
|---|---|---|---|
| **Geral** (Etapa 1, PR #922) | chave `convenio.ativo` em `ConfiguracaoGlobal` | Textos e selos somem na hora (os rótulos das contas no painel, a partir do PR 2). Benefício real (desconto, espaço) **continua** até o Master desligar cada convênio. Sem e-mail. | Nenhum vínculo novo. |
| **Por UF** (OAB) | `convenio_uf`, `modo = sem` (já existe) | Como hoje: e-mail imediato aos primários da UF (AvisoDeConvenioDaUf). Vínculos OAB da UF fecham em `desde` com `uf_desligada`. | Escritório da UF não ganha vínculo OAB. |
| **Por convênio** | coluna `ativo` da tabela `convenio` / `encerra_em` | Aviso prévio (§6.2), depois fecha com `convenio_desligado`. | Código recusado; sem vínculo automático desde o clique. |
| **Por instituição** | todos os convênios com a mesma `instituicao_chave` | Igual ao por convênio, em cada um, numa transação só. | Igual. |

**Recomendação:** a chave geral é chave de **exibição e de emergência** (desliga e religa sem efeito em dinheiro);
encerrar benefício real é sempre por convênio ou instituição, com aviso.

### 6.2 Aviso prévio e fim do benefício

1. **Prazo:** 30 dias corridos (um ciclo mensal). O Master pode escolher a data, nunca menos de 30 dias quando o
   convênio tem benefício real (`desconto_*` ou `espaco_extra`). Para `gratuidade`, pode ser na hora.
   **Recomendação:** 30 dias.
2. **E-mail:** um por primário ativo dos escritórios vinculados, pela porta única de envio, tipo transacional, origem
   `convenio` (generalização de AvisoDeConvenioDaUf.php:26), dentro da transação da mudança (molde
   AlterarConvenioDaUfUseCase.php:54-65: falha ao enfileirar desfaz). Grava `aviso_enviado_em` e `fim_previsto`.
3. **No dia** (`encerra_em`), a tarefa diária fecha os vínculos e:
   - **desconto:** `alterarValor` para o preço cheio na próxima cobrança; o plano continua o mesmo;
   - **espaço extra:** o limite volta ao do plano; acima da cota, envios pausados pela faixa de bloqueio
     (SituacaoDoEspaco.php:91), consultar e baixar continua; **nada é apagado**; o primário recebe o aviso de espaço
     que já existe, sugerindo ampliar;
   - **gratuidade:** só rótulo e selo.
4. **Nunca** muda a conta de plano sozinha (regra 2 do §2).
5. Religar antes do dia cancela o fim (`encerra_em` volta a nulo) e manda e-mail curto "o convênio continua".

## 7. Telas do Master

Não há desenho para estas telas. **Recomendação:** convenção do painel, discreta, sem cartão novo em Planos e limites;
se o Farlei quiser desenho, ele pede no Claude Design antes do PR 4 (D10).

1. **Planos e limites** (Etapa 1, feito no PR #922): acima da faixa, à direita, uma chave pequena e lilás "Convênio OAB"
   com o estado ("ligado em todo o sistema" ou "desligado: ...") e o link de texto "Convênio OAB por estado", que abre a
   seção Contas. Desligada, somem a faixa e a tag do Free, e a nota dos planos troca para `nota_sem_convenio` (sem
   "pelo convênio OAB"). O link de texto "Convênios" (lista do item 2) entra ao lado no PR 4; nada mais na seção.
2. **Lista de convênios** (PR 4), aberta pelo link: janela no host `#mp-modal` (o mesmo do modal de limite), cabeçalho
   `.mp-cab-roxo`, linhas `.mp-lin` (master.css:169-171): nome, instituição, abrangência, benefício, contas vinculadas,
   validade e a chave ligado/desligado. Botão `.mp-btn-roxo` (master.css:415) "Novo convênio": roxo porque é só do
   Master. A linha da OAB leva ao painel "Convênio OAB por estado" que já existe (não duplica).
3. **Criar e editar**: mesmo modal, campos do §3.1. `chave` e `prova` não mudam depois de criado. Gera e mostra o
   código de convite, com "Gerar outro" (o anterior deixa de valer para novos usos).
4. **Desligar**: confirmação com a prévia "{n} conta(s) vinculada(s) · benefício termina em {data} · {n} e-mail(s)
   serão enviados". Na lista, o convênio com fim marcado mostra "termina em {data}" e "Cancelar fim".
5. **Desligar por instituição**: no mesmo modal, quando a instituição tem mais de um convênio, "Desligar todos de
   {instituição}".
6. **Contas**: o rótulo da conta (painel.js:804-810) passa a vir de `ConveniosDoEscritorio` (texto_selo do convênio
   vinculado; "prova pendente" em âmbar).
7. **Trilha:** `Convenio` e `ConvenioVinculo` são `Auditavel`; rótulos em src/Master/Service/RotuloDeAuditoria.php;
   convênio gravado dentro de `EscopoDeAuditoriaDePlataforma` (como AlterarConvenioDaUfUseCase.php:84).
8. Textos em `translations/master/messages+intl-icu.pt_BR.yaml` (bloco `master.js.convenios`), sem travessão.

Rotas (proposta, prefixo `master_`, fora da guarda de produto): `master_convenio_lista` (GET `/master/convenios/lista`),
`master_convenio_salvar` (POST `/master/convenios/salvar`), `master_convenio_ligar` (POST
`/master/convenios/{id}/ligar`), `master_convenio_instituicao_desligar` (POST
`/master/convenios/instituicao/{chave}/desligar`). CSRF por header, como ConveniosDaOabController.php:43.

## 8. Textos para o cliente

Tudo depende de `ConveniosDoEscritorio`; sem convênio vigente, o texto some (não fica vazio).

| Onde | Hoje | Proposta |
|---|---|---|
| Contribua, selo do pacote gratuito (index.html.twig:168-171) | sempre "CONVÊNIO OAB · Gratuito para advogados" | `texto_selo` do convênio de gratuidade vigente; nenhum, sem selo |
| Contribua, seção `#convenio`, link "Por que é gratuito" e pergunta (index.html.twig:36, :67-80, :87) | sempre | só com a chave geral ligada (feito no PR #922, com o modal de benefícios); o texto fala da OAB só se o escritório tem vínculo OAB (PR 6) |
| Contribua, pacotes pagos | nada | com desconto vigente: "Com o convênio {nome}: R$ {valor}/mês" sob o preço |
| Meu Perfil, sob "Assinatura de espaço" (_assinatura_no_perfil.html.twig:10; profile/index.html.twig:463) | nada | uma linha: "Convênio {nome} · {benefício}"; com fim marcado, "termina em {data}"; para primário, o link "Tenho um código de convênio" |
| Cadastro (`/cadastro?convenio=CODIGO`) | nada | uma linha discreta sob o formulário: "Convênio {nome} aplicado". Sem código, nada muda |
| E-mails | AvisoDeConvenioDaUf (translations :1395-1408) | mesmos moldes para convênio e instituição (entrada, fim marcado, fim cancelado) |

**Recomendação:** nenhum texto de convênio no cadastro sem código; o selo nunca promete o que a conta não tem.

## 9. Permissões

- **Master:** `#[IsGranted('ROLE_SUPER_ADMIN')]` na classe de cada controller novo (molde
  ConveniosDaOabController.php:29). As camadas Módulos, Admin e Recursos (docs/AUTORIZACAO.md §1) não se aplicam: o
  Master passa por bypass (§2a) e a rota é de plataforma.
- **Código de convite no Meu Perfil:** rota do núcleo (`app_profile_convenio_codigo`, POST, CSRF), com o escritório da
  sessão e checagem de primário (`RegraDosPrimarios`), não por módulo. Secundário recebe 403.
- **Leitura pelo cliente:** só do próprio escritório (o `tenant_id` da sessão, nunca vindo do pedido).
- Teste functional para cada uma: Master 200, primário 200, secundário 403, anônimo redireciona ao login.

## 10. Riscos e pacote jurídico

| Risco | Cuidado |
|---|---|
| Cobrança errada no desconto | PR 8 ALTO; valor só por `ConveniosDoEscritorio`; `alterarValor` com desfazer; teste do mínimo do Asaas |
| Fim de benefício surpreender o cliente | aviso de 30 dias por e-mail e linha no Meu Perfil; nada apagado |
| OAB divergente por falso negativo | 30 dias de prova pendente antes de fechar (§5.2) |
| Código vazado | limite de usos, gerar outro, RateLimiter, Master encerra vínculo |
| LGPD | vínculo guarda só escritório e datas; sem lista de terceiros; CASCADE na purga |
| Chave geral desligada por engano | sem efeito em dinheiro; religar devolve tudo; fica na Trilha |

**Para o pacote jurídico** (`~/projetos/bluejus-coordenacao/pacote-juridico.md`, bloco "Convênios (24/11/2026)";
jurídico não trava, a coordenação copia este bloco):

1. Uso do nome e do logo da OAB ("Convênio OAB", `images/logo/oab-logo.png`) sem convênio formal assinado com cada
   seccional: risco de marca e de propaganda. A chave geral (Etapa 1) permite tirar tudo do ar na hora.
2. Termo de uso: convênio pode acabar; aviso de 30 dias; o desconto e o espaço extra terminam; nada é apagado.
3. Contrato modelo com a instituição: quem repassa o código, prazo, o que acontece no fim.
4. Lista de membros enviada pela instituição (se um dia houver): base legal e papel de cada parte (controlador e
   operador).
5. Cobrar mensalidade de quem fica fora do convênio (D3, não recomendado agora) exigiria mudar o termo antes.

## 11. Divisão em PRs

Um PR por etapa, cada um com seu arquivo em `mudancas/` (validar com `scripts/mudancas.py validar`) e a revisão do
`feature-review-agent` antes de abrir. **Só o PR 2 tem migration.**

| PR | O quê | Migration | Testes |
|---|---|---|---|
| **1 (Etapa 1, PR #922)** | chave geral `convenio.ativo` (prefixo `convenio.` em ConfiguracaoGlobal, sem chave = ligado), lida pela `ChaveDoConvenio`; `POST /master/convenio` (`AlterarConvenioAtivoUseCase`, CSRF, sem `ativo` = 400); desligada: somem a faixa, a tag do Free e a menção na nota de Planos e limites, e no Contribua a seção, o atalho, a pergunta da FAQ, o selo e o modal; link discreto para o painel por estado; Trilha | não | unit da chave e do UseCase (`ChaveDoConvenioTest`); functional do POST (Master 200, outros 403, CSRF, 400); functional do Contribua com a chave desligada e religada |
| **2** | tabelas `convenio` e `convenio_vinculo`, linha `oab`, entidades, repositórios, `ConveniosDoEscritorio`; `ConsultarContasUseCase` passa a ler por ele, mesma saída (o selo do Contribua fica para o PR 6, decisão de 24/11 em §3.4); o rótulo de convênio das contas no painel passa a obedecer à chave geral (ficou fora do PR #922) | **sim** | `down()` testado; unit de `ConveniosDoEscritorio` (chave geral, `sem`, `dif`, validade, inativo); teste de arquitetura do repositório; resultado igual ao de hoje nos testes do painel e do Contribua |
| **3** | `SincronizarVinculosDeConvenioUseCase`, tarefa diária, gancho na mudança de estado e no cadastro; prova pendente da OAB divergente | não | unit: cria, não duplica, fecha por estado, por `sem`, por divergente após 30 dias; functional da tarefa no agendador |
| **4** | telas do Master (§7): lista, criar, editar, ligar, desligar com aviso, por instituição; e-mails | não | unit dos UseCases (aviso só com benefício real, desfaz se o e-mail falha, instituição numa transação); functional das rotas; textos sem travessão (`SemTravessaoNovoArquiteturaTest`) |
| **5** | código de convite: Meu Perfil e `/cadastro?convenio=` | não | unit da validação (inativo, vencido, limite, UF errada); functional: primário 200, secundário 403, RateLimiter |
| **6** | textos para o cliente (§8): Contribua, Meu Perfil, rótulo das contas | não | functional das telas com e sem convênio, com fim marcado |
| **7** | benefício `espaco_extra` em SituacaoDoEspaco.php:86 | não | unit do retrato (maior vence, fim volta ao plano, bloqueio sem apagar) |
| **8 (ALTO)** | desconto nos planos pagos: IniciarAssinaturaUseCase.php:79, AmpliarEspacoAutomaticamenteUseCase.php:197, `alterarValor` na entrada e no fim, receita estimada | não | unit do cálculo (percentual, fixo, mínimo, maior vence); functional com gateway falso (entrada, fim, falha desfaz); re-revisão antes de seguir |

Ordem: 1 → 2 → 3 → (4, 5, 6 em paralelo depois do 3, cada um em sua worktree) → 7 → 8. Testes no container
(`php bin/phpunit --filter <Nome>`), `scripts/qa.sh` antes de cada PR. O smoke no navegador é do Samuel; cada PR diz
o que olhar na tela.

## 12. Decisões para o Samuel aprovar

**Aprovado pelo Samuel em 24/11:** D1 a D13, todas pela recomendação (D3 = não cobrar).

- D1. Manter `convenio_uf` como exceções por UF do convênio `oab`, sem mover dado. **Recomendo: sim.**
- D2. Instituição como texto + `instituicao_chave` no convênio, sem tabela própria. **Recomendo: sim.**
- D3. Cobrar mensalidade de quem está fora de convênio. **Recomendo: não nesta spec;** o Free segue grátis para todos.
- D4. Benefícios: gratuidade, espaço extra e desconto (percentual ou fixo), um por convênio; do mesmo tipo vale o maior. **Recomendo: sim.**
- D5. Desconto no Asaas por último (PR 8), com `alterarValor` e mínimo do Asaas. **Recomendo: sim.**
- D6. Vínculo OAB automático pela UF do escritório e OAB não divergente do criador, preenchido pela tarefa diária (sem INSERT na migration). **Recomendo: sim.**
- D7. OAB divergente: 30 dias de prova pendente antes de fechar o vínculo. **Recomendo: sim.**
- D8. Outras instituições só por código de convite; domínio e lista ficam fora. **Recomendo: sim.**
- D9. Chave geral só de exibição e emergência; benefício real termina por convênio, com aviso de 30 dias. **Recomendo: sim.**
- D10. Telas do Master sem desenho, por convenção do painel (link "Convênios" na faixa, lista em modal, roxo do Master). **Recomendo: sim;** desenho só se o Farlei pedir antes do PR 4.
- D11. Código de convite digitado só por primário (titular ou sócio), no Meu Perfil ou no cadastro. **Recomendo: sim.**
- D12. Vínculo fora do `TenantAware`, com repositório único e teste de arquitetura, como o `BonusDeEspaco`. **Recomendo: sim.**
- D13. Oito PRs, só o PR 2 com migration. **Recomendo: sim.**
