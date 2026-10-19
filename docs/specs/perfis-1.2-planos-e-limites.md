# Perfis 1.2 · Planos e limites no painel do Master

Frente `perfis-planos` · base: `contribua-cora` (#64) depois de ela trazer o master com o #52 e o #72 · risco
**MÉDIO** (limites e preços; nada de identidade) · rascunho de 19/10/2026.

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
