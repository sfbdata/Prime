# Gênero no cadastro, janela para quem não informou e Dr./Dra. na esteira das Metas

Risco ALTO (identidade User). Pedido do dono (23/10/2026, via coordenador). Proposta aprovada pelo coordenador em
23/10. Frente da 9b, `genero-no-cadastro`. Base: `meu-perfil-genero-atuacao.md` (o campo já existe no Meu Perfil).

## Estado da frente

- 23/10: spec; implementação começando.
- 23/10: D1 do dono (só Feminino e Masculino; `nao_informado` vira legado). Implementado na branch, sem migration: o
  perfil nasce na criação da conta (`UserProfile::daContaNova`, em cascata com o User). Janela em
  `layout/_globais/genero.html.twig` + `janela-genero.js/.css`, POSTs em `GeneroDoPerfilController`
  (`/perfil/genero` e `/perfil/genero/fechar`, CSRF `perfil_genero`; o X guarda a marca na sessão). Dr./Dra. por
  `TratamentoDeAdvogado` (código `dr`/`dra`; o texto vem do catálogo `metas.esteira.tratamento_*`). Suíte completa
  da frente verde e `scripts/qa.sh` limpo. Falta: revisão (`/review`), smoke do dono e PR.
- 23/10: correções da revisão do 7951dd895 (D2 e a consequência do legado na D1), com testes sem escritório na
  sessão (vários escritórios sem seleção, Convidado do Trabalho Externo e Master no painel).

## O que já existe

`App\Profile\Enum\Genero` (`feminino` "Feminino", `masculino` "Masculino" e o legado `nao_informado`, ver D1),
coluna `user_profiles.genero` (nulo = não preenchido, com CHECK), editada no Meu Perfil (`DadosPessoaisType`,
`AtualizarDadosPessoaisUseCase`). O perfil é criado sob demanda (`ObterOuCriarPerfilUseCase`). Sem migration nova
se der para gravar o gênero no perfil já na criação da conta; a 150500 só se for inevitável (avisar o coordenador).

## Regras

1. **Campo obrigatório** "Gênero" (só Feminino e Masculino, D1), sem valor pré-escolhido, em:
   - cadastro da tela de entrada (modal, passo "Criar conta"), com os componentes de campo do próprio desenho 10
     (**desvio autorizado**: o desenho 10 não tem o campo; pedido do dono);
   - aceite de convite do escritório sem conta, aceite de convite da plataforma (`auth/convite/ver.html.twig`) e
     convite do Trabalho Externo (`trabalho_externo/convite.html.twig`);
   - Meu Perfil: continua com o campo opcional (para não travar quem edita outros dados), agora só com as duas
     opções.
   Sem escolha, o envio é recusado com "Informe o gênero." (servidor e tela). Valor fora das duas opções (inclusive
   `nao_informado`): a mesma recusa. Quem aceita convite já tendo conta não vê o campo.
2. **O admin do escritório não edita o gênero de outra pessoa** (dado pessoal): nada muda em
   `TenantUserProfileController`.
3. **Janela para quem não informou:** todo usuário logado com `genero` nulo, `nao_informado` (legado) ou sem perfil
   vê, em qualquer tela do layout, uma janela no molde do aviso de espaço (`layout/_globais/aviso_espaco`: parcial em
   `layout/_globais/`, JS e CSS próprios, componentes do desenho, sem modal do Bootstrap). Conteúdo: título
   "Como devemos tratar você?", texto "Usamos essa informação para escrever o seu nome corretamente no sistema (por
   exemplo, Dr. ou Dra.).", as duas opções e o botão "Salvar"; o X fecha. Salvar grava pelo mesmo caminho do Meu
   Perfil (POST próprio com CSRF, só o próprio usuário) e a janela some. Fechar vale só para a sessão: volta no
   próximo login, até o gênero ser preenchido. A janela não aparece
   nas telas sem o layout (entrada, véu, gate dos Termos) nem para quem está no gate dos Termos.
4. **Dr./Dra. na esteira das Metas** (desenho 04, `trat()`, L155-162): na lista "avisados" da caixa
   (`templates/tarefa/_esteira.html.twig`, nomes de `MontarEsteiraDaPastaUseCase`), quem é advogado (OAB no
   cadastro: `User::oabNumero` preenchido) com gênero Feminino aparece "Dra. Nome", com Masculino "Dr. Nome". Sem
   OAB, sem gênero ou com o legado `nao_informado`: só o nome, como hoje. O nome continua o `nomeCurto`. O desenho
   adivinha o gênero pela terminação do nome; aqui não se adivinha (decisão do dono). A dica (`avisados_dica`)
   segue o mesmo tratamento.
5. Textos sem travessão; os textos novos entram no catálogo de idioma nas áreas já extraídas (entrada, auth,
   metas, moldura), no molde do #228.

## Decisões

- **D1 (dono, 23/10/2026, via coordenador): o gênero tem só Feminino e Masculino.** "Prefiro não informar" sai das
  opções em todo lugar: cadastro, os três convites, a janela e o Meu Perfil. Sem migration: o caso `nao_informado`
  continua no enum `Genero`, marcado como legado e nunca oferecido, para não quebrar a leitura de um valor antigo
  (0 registros em produção e no saas_ux em 23/10). Se aparecer, conta como NÃO preenchido: a janela aparece, o
  Dr./Dra. não sai e o Meu Perfil mostra o campo vazio. Formulários e janela recusam `nao_informado` com "Informe o
  gênero.".
  Consequência aceita: quem tem o legado e salva o Meu Perfil (por qualquer motivo) passa a ter o gênero nulo, porque
  o campo abre vazio. Pela D1 os dois querem dizer "não preenchido", e há 0 registros; o código não trata à parte.
- **D2 (revisão, 23/10): a janela funciona sem escritório na sessão.** Os POSTs `app_profile_genero` e
  `app_profile_genero_fechar` passam no `TenantContextValidatorListener` só no ramo SEM escritório na sessão
  (`ROTAS_SEM_ESCRITORIO`; só usam o usuário logado); com escritório, enfrentam as barreiras de sempre (conta suspensa,
  vínculo removido, em análise ou suspenso). A janela não aparece no gate dos Termos (tela sem o layout). O JS só dá por sucesso a resposta 2xx sem redirecionamento com `{ok: true}`; senão a janela fica, com "Não foi
  possível salvar. Tente de novo.". Com o aviso de espaço aberto, a janela entra logo abaixo dele, sem cobrir.

## Testes

- Unit: os UseCases de criação de conta (cadastro, convites, Trabalho Externo) gravam o gênero; recusam sem ele ou
  com valor inválido; o tratamento Dr./Dra. (advogado F, advogado M, advogado sem gênero, advogado com o legado
  `nao_informado`, não advogado com gênero).
- Functional: o campo nos 4 formulários (filho direto do lugar certo), sem a opção "Prefiro não informar" (nem na
  janela nem no Meu Perfil); a recusa; um perfil com `nao_informado` vê a janela e não ganha o título; a janela
  aparece para quem não tem gênero, some depois de salvar, volta no próximo login depois de fechada, não aparece para quem tem; o POST
  da janela exige CSRF e só grava o próprio usuário; a esteira mostra "Dra." / "Dr." / só o nome.
