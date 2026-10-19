# Perfis 1.2 · Planos e limites no painel do Master

Frente `perfis-planos` · **empilhada sobre `contribua-cora` (#64)** em 1426eb63 (já contém o master com o #52 e o #72) ·
risco **MÉDIO** (limites e preços; nada de identidade) · aberta em 19/10/2026. **Sem migration própria.**

## Estado da frente (atualizado em 19/10/2026)

- **Base:** #64 da f6. O deploy leva os dois juntos e o merge deste PR vem depois do #64.
- **Contrato f6** (nomes finais no #64):
  - leitura por `SituacaoDoEspaco::doEscritorio` → `RetratoDoEspaco` e `CatalogoDePlanos::todos()`;
  - escrita por `AlterarPlanoUseCase`, `DefinirLimiteDeArmazenamentoUseCase`, `DefinirLimiteDeUsuariosUseCase` e
    `ConfigurarFaixasDoEspacoUseCase`;
  - a auditoria fica com o controller do Master: `executarComoConta` para os limites, escopo de plataforma para o
    plano e as faixas;
  - nunca ler `EspacoDoEscritorio` fora do Contribua.
- **Decisões do dono:**
  - os planos da D5, editáveis pelo Master;
  - bloqueio de envio desligado, com o aviso ao lado da chave.
- **Referência visual:** `docs/design/perfis-1.2/referencia-planos-e-limites.md`, com as capturas no scratchpad da
  sessão b5.
- **Implementado (19/10):** P1 a P11 na worktree, com testes verdes na frente. Pontos para o orquestrador no
  relatório da implementação (leitura das faixas, teste de arquitetura, convite aceito sem nova checagem).
- **Próximo:** revisar, fazer a verificação visual e abrir o PR empilhado.

## 1. Fontes

- Desenho `05 - Perfis 1.2 - VS 18.10.26.dc.html`:
  - Armazenamento: faixas de alerta e ranking, linhas 194-203;
  - Planos e limites: linhas 205-210 e 697;
  - modal de limite: linhas 397-410 e 726-732;
  - menu da conta: linhas 170-176 e 734-735.
- Contrato com a Contribua (spec `perfis-1.2-contas.md` §7), com os nomes finais do #64:
  - `SituacaoDoEspaco::doEscritorio` → `RetratoDoEspaco`;
  - `DefinirLimiteDeArmazenamentoUseCase`;
  - `DefinirLimiteDeUsuariosUseCase`;
  - `ConfigurarFaixasDoEspacoUseCase`;
  - `AlterarPlanoUseCase`;
  - `CatalogoDePlanos::todos()`.
- Decisões do dono (19/10):
  - planos (os preços vêm do desenho 08):

    | Plano | Espaço | Preço | Usuários |
    |---|---|---|---|
    | Free | 10 GB | R$ 0 | 3 |
    | Essencial | 20 GB | R$ 19,90 | 8 |
    | Profissional | 50 GB | R$ 39,90 | 15 |
    | Avançado | 100 GB | R$ 69,90 | sem limite |

  - os valores são editáveis pelo Master nesta tela;
  - o bloqueio de envio nasce desligado e mostra um aviso ao lado da chave.

## 2. O que entra

| # | Item |
|---|---|
| P1 | **Planos e limites**: os cartões do desenho, com nome, selo "Padrão: toda conta nova começa aqui" no Free, preço por mês, "N GB · N usuários" (ou "sem limite") e quantas contas usam cada plano. Os valores vêm de `CatalogoDePlanos::todos()`. A nota do desenho fica, sem "Preços de exemplo", porque agora os preços são reais. O estado vazio da D1 sai. |
| P2 | **Editar plano** (só Master): modal com nome, espaço (GB), preço (R$), limite de usuários (vazio = sem limite), descrição e ativo. O código do plano não muda. Grava por `AlterarPlanoUseCase`, no escopo de plataforma. As mensagens de `\DomainException` aparecem no modal. O Free não pode ser desativado nem ganhar preço. |
| P3 | **Menu da conta**: "Editar limite de armazenamento" (`bi-hdd`) e "Definir limite de usuários" (`bi-people`) no topo, como no desenho. |
| P4 | **Modal de limite**, igual ao desenho: quadros EM USO / LIMITE ATUAL / LIVRE (armazenamento) ou USUÁRIOS / LIMITE ATUAL / VAGAS (usuários); campo "NOVO LIMITE (GB)" ou "NOVO LIMITE DE USUÁRIOS"; "JUSTIFICATIVA" com o placeholder "Obrigatória, fica na auditoria"; mensagens de diferença e de recusa do desenho; botão "Confirmar alteração", desabilitado quando o valor é inválido. Grava pelos UseCases da Contribua no escopo **da conta alvo** (`executarComoConta`). Inclui a opção "sem limite" do contrato (`semLimite`). |
| P5 | **Lista de contas e ranking de Armazenamento**: o plano, o limite, a % da cota, a faixa e a previsão saem do `RetratoDoEspaco` e substituem os "sem …" de hoje. O ranking ganha o botão "Editar limite". |
| P6 | **Faixas de alerta** em Armazenamento: os 4 campos (Atenção 70, Alerta 90, Crítico 95, Bloqueio 100), a nota do desenho e a chave do bloqueio com o aviso "Antes de ligar, confira os escritórios acima do plano e defina sem limite ou uma cota própria.". Grava por `ConfigurarFaixasDoEspacoUseCase`, no escopo de plataforma. |
| P7 | **Auditoria** na trilha do Master: "Limite de armazenamento" / "Limite de usuários" com "ant → novo · justificativa" (o rótulo sai da coluna que mudou no diff de `EspacoDoEscritorio`), "Plano alterado" com os campos e "Faixas de alerta alteradas". |
| P8 | **Limite de usuários no convite**: `RetratoDoEspaco::aceitaMaisUmUsuario(usuariosAtuais)` no envio de convite do escritório. Só recusa com o bloqueio ligado. A mensagem é a do desenho, se houver; senão, a da Contribua. |
| P9 | **KPI ARMAZENAMENTO** da Visão geral (desenho, linha 688): "de {cota} GB contratados · livre {X GB}", barra (uso/cota) e cor do ícone e da barra pela faixa (`faixa()` do desenho, com as faixas configuradas). A cota soma os limites do `RetratoDoEspaco` das contas fora da quarentena; conta sem limite fica fora da soma (e o uso dela fora do % e do livre). Sem nenhuma conta com limite, fica o texto de antes. |
| P10 | **Selo da auditoria** no botão "Segurança e auditoria" desde a abertura do painel (desenho, linha 678): o total vem no JSON da Visão geral (`registrosDeAuditoria`, mesmo filtro e teto da trilha). |
| P11 | **"Alerta de consumo" do Sistema** na trilha (desenho, linhas 481, 604 e 700): o ouvinte `AlertaDeConsumoNaTrilha` (domínio Master) do evento `EspacoAtingiuFaixa` da Contribua grava no `audit_log` a conta do evento, sem responsável ("Sistema"), ação própria `alerta` e o detalhe "Passou de N% do armazenamento", em que N é o início da faixa alcançada. O chip "Alertas" conta a linha e o ícone é `bi-exclamation-triangle`. |

## 3. Testes

Functional: 403 para quem não é Master em todas as escritas; CSRF; editar plano (caminho feliz e cada recusa da
Contribua); limite com e sem justificativa, abaixo do uso e sem limite; a trilha grava na conta certa; o painel
mostra os valores do catálogo; o convite é recusado só com o bloqueio ligado. Verificação visual item a item
contra o desenho.

## 4. Referência do desenho (pacote em scratchpad/ref-planos/REFERENCIA.md, capturas de 1580x900)

Regras extraídas:
- "Confirmar alteração" fica bloqueado com valor menor ou igual a 0, valor abaixo do uso ou justificativa
  vazia (só espaços contam como vazia). Repetir o valor atual é permitido e mostra "Diferença: +0 GB.".
- Usuários: o uso conta os cadastrados (secundários + 1 primário). A recusa é "Menor que os usuários já
  cadastrados.".
- Os chips de configuração (Atenção / Alerta / Crítico / Bloqueio) e a função `faixa()` das barras ("Limite
  atingido", Alerta `#c0392b`) têm nomes diferentes, os dois vindos do desenho. "Perto ou acima da franquia"
  conta pela faixa de Alerta.

O desenho é omisso nestes pontos; vale a convenção do sistema, registrada no PR:
- "sem limite" de usuários aparece como "usuários sem limite", não "sem limite usuários";
- cor do 4º plano (Avançado);
- botão e modal "Editar plano" (P2);
- ordem e validação das faixas: crescentes, de 1 a 100;
- botão para salvar as faixas;
- GB com uma casa decimal;
- plural da previsão ("≈ 1 mês" / "≈ N meses").

## 5. Próxima frente da b5 depois desta: C6 (saídas na tendência por estado)

Contrato com a 7d (19/10):
- A 7d cria a tabela `saida_da_equipe`, só de inclusão, num PR depois do #69, com migration na fila. Colunas:
  `tenant_id`, `user_id` (ON DELETE SET NULL), `motivo` (removido | saiu), `saiu_em` e `era_primario`.
- A tabela é gravada na mesma transação de "demitir" e de "sair do escritório", e entra na ordem de deleção da purga.
- A b5 só lê: saídas por UF da pessoa (mesmo critério das entradas; se não houver usuário, vale a UF do escritório)
  nos últimos 30 dias e nos 30 anteriores, com o filtro "Só primários" por `era_primario`.
- Isso troca o title "só entradas: saídas ainda não são medidas" pela tendência completa do desenho.
- "sem limite" no modal só no armazenamento (o `semLimite` do contrato libera espaço e usuários); no modal de
  usuários, vazio não volta ao limite do plano (o contrato aceita nulo, a tela não oferece);
- conta em quarentena: sem os itens de limite no menu e recusada no servidor; na lista, "uso não medido";
- previsão negativa (uso acima do limite) sai como "menos de 1 mês", como a fórmula do desenho.

## 6. Acertos com a f6 (19/10, depois da implementação)

- Leitura das faixas: `ConfiguracaoDoEspacoRepository::atual()` é contrato de leitura aceito pela f6 (devolve os
  padrões em memória sem gravar). A escrita só pelo `ConfigurarFaixasDoEspacoUseCase`.
- Teste de arquitetura da f6 (`EspacoDoEscritorioSoNoContribuaArquiteturaTest`): liberados só
  `PainelMasterRepository` e `RotuloDeAuditoriaDoEspaco` para citar a classe, com comentário do porquê. A
  proibição de consulta à tabela continua.
- Aceite de convite: o limite de usuários também é conferido no aceite (`AceitarConvite`, com e sem conta), com
  `SituacaoDoEspaco::retratar($tenantId, 0)->aceitaMaisUmUsuario()` (serviço `Auth\Service\VagaNoEscritorio`,
  o mesmo do envio). "Sem limite" libera, e a regra só vale com o
  bloqueio ligado.
- O plano do escritório pela assinatura (C3 da f6) é da f6. O `AlterarPlanoUseCase` do Master edita só o
  catálogo. O "plano pago até", se entrar, é lido de
  `MontarPagamentoDoContribuaUseCase::assinaturaDoEscritorio($tenantId)`.
- Retratos em lote (19/10, commit c29b7a8c da f6): a lista de contas, o ranking, o KPI e o "plano da conta" da
  trilha usam `SituacaoDoEspaco::retratarVarios` (uma consulta IN para os espaços; o Free e a configuração lidos
  uma vez), no lugar de um `retratar` por conta. Saiu a dívida N+1. O convite (envio e aceite) usa
  `retratar($id, 0)`, sem medir o espaço.
- KPI ARMAZENAMENTO com conta sem limite (decisão do orquestrador, revista na verificação visual de 19/10): o
  número grande, a descrição e a barra somam só as contas com limite, como o desenho (a cota é delas); com conta
  sem limite, a descrição acrescenta " · + Z GB em N conta(s) sem limite". Todas sem limite: vale o texto antigo,
  com o uso total.
- Trilha da primeira mudança de limite: o anterior sai em número ("10 GB → 12 GB", "3 usuários → 5 usuários"),
  com a cota e o limite de HOJE do plano da conta (o `audit_log` não guarda os de então).
- Medidas dos campos como no desenho (content-box): caixa das faixas 72x30, campos do modal com 40px.
