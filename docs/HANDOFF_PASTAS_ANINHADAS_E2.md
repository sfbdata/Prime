# HANDOFF — Entrega 2: o Drive espelha a árvore de pastas

**Aberto em 2026-08-21.** A Entrega 1 (pasta dentro de pasta **no sistema**) está **EM PRODUÇÃO**.
Esta é a fatia seguinte: fazer o Google Drive refletir essa árvore, nos dois sentidos.

**Leia nesta ordem:**

1. Este arquivo — estado, o furo do desenho e a decisão que trava o começo
2. `docs/specs/pasta-subpastas-aninhadas.md` **§8** — o desenho técnico da Entrega 2
3. `docs/specs/sincronizacao-drive-bidirecional.md` **§11.6** — a declaração canônica que esta entrega altera
4. `docs/HANDOFF_PASTAS_ANINHADAS.md` — histórico da Entrega 1 (só se precisar do porquê de algo)

---

## 1. Onde a Entrega 1 chegou (não repita este trabalho)

**EM PRODUÇÃO desde 21/08.** Verificado direto no banco de prod, não pelo log do deploy:

| | |
|---|---|
| coluna `secao_pai_id` | existe, migration `Version20260819175112` registrada |
| pastas (`pasta_secao`) | **623**, todas ainda na raiz — a migration não remexeu nada |
| documentos na raiz das pastas | **12.611** intactos (de 21.221 no total) |
| suíte | 3976/3976 no master |

O que o usuário tem hoje: criar pasta dentro de pasta até 10 níveis, navegar com caminho clicável,
mover **pelo menu "Mover para..."**, apagar com aviso que conta o conteúdo, e busca que diz em que
pasta cada arquivo está.

🔑 **Arrastar uma pasta para dentro de outra NÃO EXISTE, e foi decisão do dono (21/08).** O SortableJS
reposiciona o cartão arrastado sob o cursor, então ao soltar "em cima" de outro cartão quem está ali é
o próprio arrastado. Medido no navegador: `drop` chegou no próprio arrastado e **zero eventos de
`dragover`**. O código foi removido. Arrastar pela alça reordena, e só. **Não reimplemente** — se o
gesto do Drive for pedido de novo, a saída é reconstruir o gerenciador com listas Sortable aninhadas,
que é frente própria.

---

## 2. 🔴 O FURO DO DESENHO — resolva isto antes de escrever código

A §8.2 da spec propõe a transição assim:

> 1. procura a pasta por `drive_folder_id`;
> 2. não achou → procura **por nome sob o mesmo pai**; ao achar, **grava o `drive_folder_id` ali** e segue.

Chamei isso de "auto-curativo". **É, mas herda exatamente o defeito que deveria resolver.**

Medido em produção (21/08):

| | |
|---|---|
| pastas com nome repetido dentro do mesmo caso | **56** |
| grupos ambíguos | **28** |
| pastas de caso afetadas | **24** |
| pior grupo | 2 pastas com o mesmo nome |

Nesses 28 grupos, "procurar por nome" devolve **duas candidatas** e a adoção gravaria a identidade do
Drive **na pasta errada**. Isso é pior que o defeito atual: em vez de errar uma importação, **fixa o
erro para sempre** — daí em diante o casamento por id sempre apontará para a pasta trocada.

São 56 de 623: **9% das pastas**.

### A recomendação (decisão do dono, ainda não tomada)

**Quando houver mais de uma candidata pelo nome, NÃO adotar.** Deixar sem `drive_folder_id`, registrar
no log, e devolver a lista para decisão humana. É a lição que este projeto já tem escrita em
`feedback_quando_o_dado_que_decide_nao_esta_no_banco`: quando o dado que decide não está no banco, o
comando para de decidir e pergunta.

O custo é que 56 pastas ficam sem identidade e continuam dependendo do nome até alguém desempatar.
O benefício é não gravar 28 palpites permanentes.

**Alternativas que o dono pode preferir:** adotar pela mais antiga (`MIN(id)`), ou adotar e marcar
para revisão. Qualquer uma serve — o que não pode é decidir em silêncio.

---

## 3. O que a Entrega 2 faz (desenho já feito, §8 da spec)

- **Coluna nova:** `pasta_secao.drive_folder_id VARCHAR(255) NULL` + índice. **Tem migration** — ver §5.
- **Ida (sistema → Drive):** `resolverPastaAlvoNoDrive` sobe a cadeia de pais e garante cada nível no
  Drive, find-or-create por `drive_folder_id` em vez de por nome.
- **Volta (Drive → sistema):** `coletarArquivosRecursivo` deixa de achatar e passa a devolver os
  arquivos **com a cadeia de pastas**; cada nível vira uma `PastaSecao` com o pai certo.
- **Acima de 10 níveis o achatamento sobrevive:** níveis 11+ vão para o nível 10, com linha no log.
- **Capacidade nova no client:** `GoogleDriveClientInterface` não tem `moverPasta`. Entra na interface,
  na implementação real e no `FakeGoogleDriveClient` dos testes.
- **Altera a §11.6** de `docs/specs/sincronizacao-drive-bidirecional.md` — a linha que hoje diz
  *"sub-subpasta (e mais fundo) → achatada para a seção avó"*.

---

## 4. O teste que vai ficar vermelho DE PROPÓSITO

`app/tests/Sync/Functional/ReconciliadorArvoreNaoRegridTest.php` existe para provar que a Entrega 1
**não** mexeu no sync — ele afirma que uma árvore de 3 níveis sobe achatada, com a subpasta filha
direta da pasta do caso.

**Quando a ida passar a espelhar a árvore, esse teste falha — e é o sinal certo.** Ele deve ser
**reescrito** para afirmar o novo contrato, nunca apagado. O próprio arquivo diz isso no docblock.

---

## 5. Pré-requisitos e bloqueios (conferidos em 21/08)

✅ **Entrega 1 em produção** — a Entrega 2 pode ser cortada do master limpo, sem empilhar.

✅ **Nenhuma outra frente com migration ativa** — a única (`cobranca-acompanhamento-canonico`) está
   🛑 PARADA. A regra da casa é uma frente com migration por vez, então o caminho está livre.

⚠️ **`expediente-ux`** toca `app/templates/pasta/` e está 28 commits atrás. A Entrega 2 mexe em
   **sync**, não em tela, então a colisão é menor que na Entrega 1 — mas confira antes de abrir.

⚠️ **A Entrega 3** (realinhar os 8.610 arquivos já achatados) depende desta, mas **não é pré-requisito
   dela** — foi justamente para isso que a adoção do §8.2 existe. Ver §9 da spec.

---

## 6. Como começar

```bash
cd /home/prime/projetos/jusprime
git pull origin master                      # a Entrega 1 já está aqui
scripts/frente-abrir.sh pasta-drive-espelha-arvore
```

⚠️ O `frente-abrir.sh` **já falhou uma vez** no `cache:clear` pós-composer (estouro de memória do PHP)
e deixou a frente pela metade. Se acontecer, complete à mão: os diretórios de `app/public/uploads/*`
(7 deles) e o clone `saas_test` → `saas_test<nome-da-frente>`.

⚠️ A worktree nasce **sem `.env.local`** — copie de `app/.env.local`, senão a tela dela aponta para o
banco errado.

### O roteiro de integração tem TRÊS bancos, não dois

Aprendido na dor em 21/08 (a suíte do master explodiu com `column ... does not exist` e parecia código
quebrado):

| banco | quem usa |
|---|---|
| `saas_test<frente>` | a suíte da frente |
| `saas_ux` | a tela (o smoke) |
| **`saas_test`** | **a suíte do master, depois do merge** ← o esquecido |

Aplicar com `doctrine:migrations:execute --up "DoctrineMigrations\VersionXXXX" --env=test` a partir do
repositório principal. **`execute`, nunca `migrate`** — o ledger dos bancos clonados só tem as
migrations recentes e o `migrate` morre tentando rodar as antigas.

---

## 7. O que continua valendo enquanto a Entrega 2 não sai

**O comando de importar do Drive** (`app:sync:reconciliar --modo=importar`) casa pastas **por nome** e
pode errar nos 28 grupos ambíguos. **Não existe botão na tela** — só o comando manual. O cron roda
`--modo=enviar` e não é afetado (tem teste provando que continua achatando como antes).

Isso **não é defeito novo** desta frente: sempre foi por nome. O que a árvore muda é a frequência —
nomes repetidos deixam de ser acidente e viram organização normal.
