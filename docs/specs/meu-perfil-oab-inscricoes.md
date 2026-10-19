# Spec: várias inscrições na OAB (PR 3 do redesenho do Meu Perfil, VS 18.10.26)

Risco: MÉDIO (credencial profissional; a revisão do Master e o cadastro dependem dela).
Desenho: `docs/design/meu-perfil-18-10/07 - Meu Perfil - VS: 18.10.26.dc.html`, card "Registro OAB" (linhas 79 a 92),
modal "Inscrição na OAB" (127 a 132) e `_salvarOab`/`ofRemover` (216 a 230). Print do dono: card com `49613/DF`,
selo PRINCIPAL, "Verificada em 10/07/2026 16:56", chip "Confirmada" e lápis; modal com o seletor Principal/Suplementar.

## 1. Regras do desenho

1. Uma pessoa tem **zero ou mais inscrições**, cada uma com número, UF (seccional), tipo e situação.
2. **No máximo uma por UF** ("Cada seccional tem uma só inscrição por advogado").
3. **Exatamente uma PRINCIPAL** quando há inscrição. Escolher outra como principal rebaixa a atual a suplementar.
   A primeira inscrição nasce principal; as seguintes nascem suplementares.
4. Inscrição nova, ou com número ou UF alterados, fica **Pendente** ("Aguardando validação do Master"); sem mudança de
   número e UF, mantém situação e data de verificação.
5. **Remover** só suplementar, e só quando houver mais de uma inscrição. A principal não se remove (troca-se a principal).
6. Lista ordenada: principal primeiro, depois por UF. Subtítulo: "Ordem dos Advogados do Brasil" com uma inscrição;
   "N inscrições · M suplementar(es)" com mais.
7. Número: de 1 a 7 dígitos. O desenho aceita uma letra final (`^\d{1,7}[A-Z]?$`), mas o banco exige só dígitos no
   espelho (`chk_user_oab_numero`); em produção (19/10/2026) havia 8 OABs, a maior com 5 dígitos, nenhuma fora do
   formato. Pontos, traços e espaços são ignorados; letra recusa com a mensagem. UF: uma das 27 seccionais.
8. Recusa ao salvar volta com o modal aberto, os valores digitados e o erro dentro dele (desenho `erroTem`/`erro`).
9. Criar a principal a partir do espelho é idempotente e à prova de duas abas (`INSERT ... ON CONFLICT DO NOTHING`);
   trocar para uma principal nova grava rebaixando a antiga antes, numa transação (o índice parcial é imediato).

Situação no sistema = `StatusOab` que já existe: NaoVerificada aparece como **Pendente**, Divergente como
**Não confirmada**, Confirmada como **Confirmada** (cores do desenho).

## 2. Dados

- Entidade nova `App\Auth\Entity\InscricaoOab`, tabela `inscricao_oab`: user (CASCADE), numero, uf, tipo
  (`principal|suplementar`), status (`StatusOab`), nome_oficial, verificada_em, criado_em, atualizado_em.
  UNIQUE (user_id, uf); índice único parcial (user_id) WHERE tipo = 'principal'. Auditavel. Não é por escritório:
  a inscrição é da pessoa (não é TenantAware), como os campos oab* do User hoje.
- **Espelho**: os campos `User.oab*` continuam e passam a ser a cópia da principal (o desenho faz o mesmo, `espelho`).
  Quem só precisa da principal (cadastro, convite, criar escritório, mapa, painel, editor de primários, Trabalho
  Externo) não muda.
- Migration com backfill: cada User com `oab_numero` vira uma inscrição principal com o status, o nome oficial e a
  data que já tem. Donos confirmados sem número (backfill de 01/07) ficam sem inscrição, como hoje.

## 3. Pontos de contato com outras frentes

- **Revisão de OAB do Master** (`/admin/platform/oab`, `ConfirmarOabManualmente`, `ReverterOab`, `VerificarOab`;
  frente b5): enquanto ela opera sobre o User, as três ações passam a atualizar também a inscrição principal (sem
  mudar a tela). Para a fila mostrar as suplementares, este PR expõe:
  - leitor `App\Auth\Repository\InscricaoOabRepository::paraRevisao(statuses, busca, pagina)` (global, só Master) e
    `doUsuario(User)`;
  - UseCases por inscrição: `ConfirmarInscricaoOabUseCase`, `ReverterInscricaoOabUseCase`,
    `VerificarInscricaoOabUseCase` (id da inscrição), que mantêm o espelho quando a inscrição é a principal.
  A b5 religa a fila e os botões quando quiser.
- **Painel do Master** (contagens de OAB): continua lendo o espelho. Se quiser contar as suplementares, usa o leitor.
- **Push Processual** (frente 0d): não lê a OAB do usuário (tem `oab_monitorada` própria). Nada muda.

## 4. Rotas do perfil

- `POST /perfil/oab` passa a receber `idx` (id da inscrição, vazio para nova), `numero`, `uf` e `tipo`.
- `POST /perfil/oab/{id}/remover` (CSRF) remove uma suplementar da própria pessoa (404 se não for dela).
- `POST /perfil/oab/verificar` continua verificando a principal (o CNA está dormente).
