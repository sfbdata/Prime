# Contas e escritórios: código da conta, ordem e busca por código (desenho 05, Perfis 1.2 de 30/10/2026)

Risco MÉDIO: só o Master (`ROLE_SUPER_ADMIN`) enxerga; toca dado de todos os escritórios (código da conta) e tem migration de dados.
Migration: `Version20261030100000` (número dado pela coordenação).

Desenho: `docs/design/redesign-30-10-26/05 - Perfis 1.2 - VS 30.10.26.dc.html` (L173-212; JS L969, L1066-1068, L1172-1183) e
`bj-contas.js` (`codigoConta`, L106-111), fora do git. Escopo: só a sub-aba "Contas e escritórios" (Perfis > Master do Sistema >
Painel do sistema).

## O que já existia (não muda)

Tabela, colunas, filtro de situação, busca por Nº, menu ⋮ e botão direito, ações, pedidos de exclusão, convênio OAB, Nº da conta
(`NumeroDaConta`: o id com 6 dígitos, que é a ordem de criação), `Tenant.codigoConta` (coluna `codigo_conta`, índice único parcial)
e `CodigoDaContaDoEscritorio` (gera sob demanda).

## O que entra

1. **Chip do código** na célula da conta: nome, linha com "Nº 000123" (texto mono 10.5px, `#5b7383`) e o código `BJ-XXXX-XXXX-XXXX`
   como chip (`#0a6fa0` sobre `#e5f1f7`, borda `#cfe3f0`, `title="Código da conta"`), depois "desde ... · último acesso". O Nº deixou
   de ser chip ao lado do nome (era do desenho de 21/10): o desenho de 30/10 manda.
2. **Dados**: `PainelMasterRepository` lê `codigo_conta`; `ConsultarContasUseCase` devolve `codigo` e `criadoEm` (ISO) por conta;
   `/master/dados/contas` repassa. O código só sai nessa rota (Master); nenhuma outra tela de escritório o recebe de outra conta.
3. **Contas sem código**:
   - Migration `Version20261030100000`: backfill único e não sequencial (`random_int`, alfabeto `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`,
     12 caracteres), conferido contra os já gravados e os sorteados na rodada; só toca onde `codigo_conta IS NULL`. `down()` vazio
     de propósito (o código pode já ter sido mostrado e usado em busca e suporte).
   - Garantia na listagem (para a conta criada depois da migration por caminho que não gera código): `ConsultarContasUseCase` gera via
     `CodigoDaContaDoEscritorio`, sem repetir dentro do lote, com UM flush. Idempotente: com todas preenchidas não toca no banco.
4. **Select "Ordem:"** (`painel.js`): nº da conta (padrão, = id), nome (A-Z, `localeCompare` pt-BR), mais usuários, mais
   armazenamento, mais pastas, mais recentes (`criadoEm`). Empate pelo nº.
5. **Busca**: placeholder "Buscar código, conta ou primário". O código casa por trecho, em minúsculas e sem hífens; Nº exato e
   nome/primário como antes.
6. **Filtro de situação**: Todas as situações, Ativa, Suspensa e (do sistema) Em quarentena.

## Desvios conscientes (a confirmar com o dono)

- **"A-Z" com hífen**: o desenho usa travessão em "nome (A–Z)"; a regra do dono (sem travessão em texto visível) manda.
- **"Teste" fora do filtro**: o desenho tem a opção, mas o sistema não tem esse estado (só Ativa, Suspensa e Em quarentena). Não
  inventado.
- **Mantido o que o sistema tem e o desenho não**: link "Convites" no cabeçalho (acesso ao recurso), situação "Em quarentena" com data
  da purga, confirmação digitada EXCLUIR, bônus de armazenamento. São regra de negócio.
- A busca do desenho calcula o Nº pela posição na lista; aqui o Nº é o id (estável, não muda com a purga).

## Testes

- Unit `ConsultarContasCodigoTest` (codigo, criadoEm, garantia sem colisão, um flush); `RetratosEmLoteNoPainelTest` ajustado ao
  construtor.
- Functional `CodigoDaContaNoPainelTest` (JSON `/master/dados/contas`: código gerado, estável, 403 fora do Master sem vazar código).
- Folha do JS/CSS/catálogo: `PainelCodigoEOrdemDasContasJsTest` (chip como filho direto, ordens, busca) e `PainelNumeroDaContaJsTest`.
