# Gênero no cadastro, janela para quem não informou e Dr./Dra. na esteira das Metas

Risco ALTO (identidade User). Pedido do dono (23/10/2026, via coordenador). Proposta aprovada pelo coordenador em
23/10. Frente da 9b, `genero-no-cadastro`. Base: `meu-perfil-genero-atuacao.md` (o campo já existe no Meu Perfil).

## Estado da frente

- 23/10: spec; implementação começando.

## O que já existe

`App\Profile\Enum\Genero` (`feminino` "Feminino", `masculino` "Masculino", `nao_informado` "Prefiro não informar"),
coluna `user_profiles.genero` (nulo = não preenchido, com CHECK), editada no Meu Perfil (`DadosPessoaisType`,
`AtualizarDadosPessoaisUseCase`). O perfil é criado sob demanda (`ObterOuCriarPerfilUseCase`). Sem migration nova
se der para gravar o gênero no perfil já na criação da conta; a 150500 só se for inevitável (avisar o coordenador).

## Regras

1. **Campo obrigatório** "Gênero" (Feminino, Masculino, Prefiro não informar), sem valor pré-escolhido, em:
   - cadastro da tela de entrada (modal, passo "Criar conta"), com os componentes de campo do próprio desenho 10
     (**desvio autorizado**: o desenho 10 não tem o campo; pedido do dono);
   - aceite de convite do escritório sem conta, aceite de convite da plataforma (`auth/convite/ver.html.twig`) e
     convite do Trabalho Externo (`trabalho_externo/convite.html.twig`);
   - Meu Perfil: continua como está (já tem o campo; segue opcional lá, para não travar quem edita outros dados).
   Sem escolha, o envio é recusado com "Informe o gênero." (servidor e tela). Valor fora das três opções: a mesma
   recusa. Quem aceita convite já tendo conta não vê o campo.
2. **O admin do escritório não edita o gênero de outra pessoa** (dado pessoal): nada muda em
   `TenantUserProfileController`.
3. **Janela para quem não informou:** todo usuário logado com `genero` nulo (ou sem perfil) vê, em qualquer tela
   do layout, uma janela no molde do aviso de espaço (`layout/_globais/aviso_espaco`: parcial em
   `layout/_globais/`, JS e CSS próprios, componentes do desenho, sem modal do Bootstrap). Conteúdo: título
   "Como devemos tratar você?", texto "Usamos essa informação para escrever o seu nome corretamente no sistema (por
   exemplo, Dr. ou Dra.).", as três opções e o botão "Salvar"; o X fecha. Salvar grava pelo mesmo caminho do Meu
   Perfil (POST próprio com CSRF, só o próprio usuário) e a janela some. Fechar vale só para a sessão: volta no
   próximo login, até o gênero ser preenchido. "Prefiro não informar" conta como preenchido. A janela não aparece
   nas telas sem o layout (entrada, véu, gate dos Termos) nem para quem está no gate dos Termos.
4. **Dr./Dra. na esteira das Metas** (desenho 04, `trat()`, L155-162): na lista "avisados" da caixa
   (`templates/tarefa/_esteira.html.twig`, nomes de `MontarEsteiraDaPastaUseCase`), quem é advogado (OAB no
   cadastro: `User::oabNumero` preenchido) com gênero Feminino aparece "Dra. Nome", com Masculino "Dr. Nome". Sem
   OAB, sem gênero ou com "Prefiro não informar": só o nome, como hoje. O nome continua o `nomeCurto`. O desenho
   adivinha o gênero pela terminação do nome; aqui não se adivinha (decisão do dono). A dica (`avisados_dica`)
   segue o mesmo tratamento.
5. Textos sem travessão; os textos novos entram no catálogo de idioma nas áreas já extraídas (entrada, auth,
   metas, moldura), no molde do #228.

## Testes

- Unit: os UseCases de criação de conta (cadastro, convites, Trabalho Externo) gravam o gênero; recusam sem ele ou
  com valor inválido; o tratamento Dr./Dra. (advogado F, advogado M, advogado sem gênero, advogado "Prefiro não
  informar", não advogado com gênero).
- Functional: o campo nos 4 formulários (filho direto do lugar certo); a recusa; a janela aparece para quem não
  tem gênero, some depois de salvar, volta no próximo login depois de fechada, não aparece para quem tem; o POST
  da janela exige CSRF e só grava o próprio usuário; a esteira mostra "Dra." / "Dr." / só o nome.
