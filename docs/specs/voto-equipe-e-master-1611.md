# Voto: equipe por assembleia, relação de votantes e estatísticas do Master (W2-7, item B11)

Risco **MÉDIO** (mexe em permissão). Decisões do Coordenador em 16/11/2026
(`bluejus-coordenacao/decisoes-voto-1611.md` e `voto-w27-perguntas-1611.md`). Desenho:
`docs/design/voto/export-2026-11-16/` ("18 - Voto - VS 08.11.26.dc.html": Perfis H247 e H463-464,
passo 6 do assistente H266, Relação de votantes H347, Estatísticas do Master H245 e H466;
`bj-voto-nucleo.js` N87-94 e N141; `bj-voto-cenarios.js` C6-27; `ESPEC_VOTO.md` E213, E233, E235, E237).

Fora desta etapa: Avaliações do conselho e ocorrências (W2-7b), Guardião e Validação (dependem de
C1/C4), acesso de membro sem conta no BlueJus.

## 1. Equipe por assembleia (bloco A)

### 1.1 Dado

Tabela `voto.membro_da_equipe` (migration `Version20261117040000`, a única desta etapa):

| coluna | regra |
|---|---|
| id, tenant_id, assembleia_id | FK; ON DELETE CASCADE pela assembleia |
| papel | enum `PapelNaEquipe`: `comissao`, `auditoria`, `fiscal`, `conselho` |
| cargo | texto até 60 (rótulo livre; a categoria preenche) |
| nome | até 120, obrigatório |
| email | até 180, opcional |
| user_id | FK opcional para o usuário do escritório; nulo = "A designar" ou pessoa sem conta |

Uma pessoa (user_id) tem um único papel por assembleia: UNIQUE (assembleia_id, user_id) onde user_id
não é nulo. Texto do desenho: "Uma pessoa não pode acumular perfis na mesma votação."

A equipe é **por assembleia** (o evento), nunca por pauta. Assembleia sem nenhuma linha = "Usar a
equipe padrão" (vale o módulo, como hoje). Com pelo menos uma linha = "Equipe própria".

Membro sem conta: nome e e-mail ficam registrados (aparecem como cargo), mas **não dão acesso**. Só
dá acesso a linha com `user_id` de usuário com vínculo ativo no escritório da assembleia.

Cargos por categoria: `CenarioDaAssembleia::cargosDaEquipe()` com os dados de `bj-voto-cenarios.js`
C16-27, **sem** Guardião e Validação. "Aplicar cargos da categoria" cria as linhas "A designar".

### 1.2 Permissão: teto, nunca acesso

Mesmo modelo do cadeado da pasta (`docs/AUTORIZACAO.md`, "Cadeado da pasta"): a equipe própria
**só tira** acesso de quem já teria pelo módulo `voto`; nunca dá acesso a ninguém.

Ponto único `App\Voto\Service\AcessoAAssembleia::nivel(User, Assembleia)` → `Oculto`, `Visualizar` ou
`Editar`:

1. Master ou primário ativo do escritório (`RegraDosPrimarios::podeGerirPrimarios`) → `Editar`.
   São "a Administração": designam a equipe e nunca são restringidos.
2. Sem acesso ao módulo `voto` pelas camadas atuais (GuardaDaEquipeDoVoto) → nada muda: a guarda
   atual já recusa.
3. Assembleia sem equipe própria → `Editar` (como hoje).
4. Com equipe própria: `comissao` → `Editar`; `auditoria`, `fiscal`, `conselho` → `Visualizar`;
   quem não está na lista (ou está só como linha sem `user_id`) → `Oculto`.

Onde vale: `BarreiraDaEquipeListener` (`kernel.controller_arguments`) em TODA rota
`voto_assembleia_*` que resolve uma assembleia (argumento `Assembleia` ou id da rota). `Oculto` →
403 em tudo, com a frase do desenho "Seu perfil não atua nesta votação."; `Visualizar` → 403 nos
métodos não seguros (POST/PUT/PATCH/DELETE), salvo leituras por POST listadas com motivo.
Assembleia de outro escritório passa reto (a action responde o 404 dela). As ~48 chamadas a
`guarda->exigir()` não mudam. Teste de arquitetura trava o alcance (todas as rotas `voto_assembleia_*`
passam pela barreira ou estão numa lista de exceção com motivo).

Listas: o Painel, a aba Votar, Acompanhar e Resultados escondem a assembleia `Oculto` para a pessoa
e não a contam nos KPIs dela.

Regras de edição da equipe (N87-94):
- só a Administração (item 1) designa, altera e remove;
- a equipe só muda com a assembleia em rascunho;
- "Usar a equipe padrão" apaga as linhas (rascunho);
- a Administração não se remove (ela não está na tabela: nunca é restringida).

### 1.3 Telas

- Tela "Perfis por assembleia ou votação" (H247): grupos "Equipe padrão (votações sem equipe
  própria)" e um por assembleia ("N representantes" / "Sem equipe própria"), botões "Aplicar cargos
  da categoria" e "Designar", campos Nome, E-mail, Cargo e Papel, textos literais do desenho.
- Passo 6 do assistente (H266): "Usar a equipe padrão" / "Equipe própria com os cargos desta
  categoria", lista cargo + nome ("A designar"), "A Administração indica o nome e o e-mail de cada
  cargo." e [Indicar pessoas] → tela Perfis. **Entra depois da W2-8** (o assistente está sendo
  montado em paralelo).
- Nome das rotas e chaves: `voto_equipe_*` e `voto.equipe.*` (não "indicações": o nome já existe no
  Master para o programa de indicação).

## 2. Relação de votantes (bloco B)

Hoje a ata mostra quem votou, com hora exata, para qualquer um com o módulo, em todo cenário.

Passa a ser:
- só nos cenários com lista (`bj-voto-cenarios.js` `lista`: condomínio, associação, sociedade); nos
  demais a ata não traz a relação (nenhuma assembleia de hoje perde: todas são de condomínio);
- só da pauta encerrada ("A relação só é emitida depois do encerramento.");
- ordem alfabética, colunas Nome, Identificação (rótulo do cenário), Situação, com "Não indica em quem
  cada pessoa votou.";
- **faixa horária** no lugar da hora exata (E237);
- quem vê: assembleia **sem** equipe própria → quem tem o módulo (como hoje); **com** equipe própria
  → só `comissao`, `auditoria` e `fiscal` (o `conselho` não), além da Administração;
- cada emissão grava "Relação de votantes emitida" na trilha (`audit_log`), no molde de
  `RegistrarAuditoriaDoAcompanhamentoUseCase`.

A relação nominal do voto aberto (W2-3) segue as mesmas regras de quem vê.

## 3. Estatísticas do Master (bloco C)

Na aba Voto do Master (`VotoDoMasterController`, `GuardaDoMasterDoVoto`), só leitura:
- "Estatísticas de todas as votações", filtro Categoria, [Exportar CSV], nota "Somente números
  agregados. O perfil não opera votações e não tem acesso ao voto de nenhuma pessoa.";
- KPIs: Votações, Realizadas, Em andamento, Aptos, Votantes e Participação nas realizadas, Quórum
  atingido "x de y". Os KPIs de ocorrências e avaliações esperam a W2-7b (não aparecem agora);
- linha por votação (pauta): escritório, entidade, título da assembleia e da pauta, "N de M votaram
  (pc) · Quórum: Atingido|Não atingido|Não exigido";
- CSV `estatisticas-votacoes.csv`, separado por `;`, com as colunas do desenho que existem no sistema;
- exclui rascunho e simulação (`a.simulacao = false`); arquivadas continuam contando;
- quórum e participação pela regra que já existe (`ApuracaoDaPauta`, `LeituraDaAssembleia`): não
  reescrever a conta em SQL;
- nunca nome de pessoa, unidade ou e-mail.

## 4. Testes obrigatórios

- Teto: sem equipe nada muda (regressão das telas atuais); com equipe, comissão edita, auditoria lê e
  recebe 403 no POST, quem não está na lista recebe 403 em leitura e escrita e não vê a assembleia nas
  listas; Master e primário sempre editam; membro sem conta não dá acesso; outro escritório 404.
- Teste de arquitetura das rotas `voto_assembleia_*` na barreira.
- Relação: cenário sem lista não mostra; pauta não encerrada não mostra; faixa horária; trilha
  gravada; quem vê com e sem equipe.
- Master: simulação e rascunho fora; números batem com a tela de Resultados; CSV.

## 5. Ciclo

Bloco C e bloco A (sem o passo 6) em paralelo; bloco B depois da W2-1c (as duas mexem na ata);
passo 6 depois da W2-8. Re-revisão do teto (bloco A) pelo `feature-review-agent` antes de integrar.
`docs/AUTORIZACAO.md` ganha a seção "Equipe da assembleia do Voto: teto por assembleia" no bloco A.
