# Spec: gênero e atuação no Meu Perfil (F1 do desenho 05 de 21/10)

Risco: MÉDIO (Profile). Pedido do dono, coordenado pela b5. Bloqueia o motor dos filtros do Comercial (frente 5d, F2).

## Estado da frente

- Sessão 7d. Base: master 08fb5f2f (#89 e #161 já mergeados). Migration `Version20261020141000` (nome dado pela
  coordenação). PR só depois de a coordenação avisar que a v2.10.0 (correção do 403) foi publicada.
- Contrato com a b5 (21/10): valores do filtro do desenho 05 (L631, função At, e L634 a L636). O desenho 07 não tem
  gênero nem atuação.
- Contrato com a 5d (21/10): leitor em lote `UserProfileRepository::atributosDe()`, abaixo. Avisar a 5d quando
  entrar.

## O que muda

- `user_profiles.genero`: `feminino` | `masculino` | `nao_informado` ("Feminino", "Masculino", "Prefiro não
  informar"), ou NULL. Enum `App\Profile\Enum\Genero`.
- `user_profiles.atuacao`: `advogado` | `estagiario` | `controladoria` | `financeiro` | `administrativo` | `outra`
  ("Advogado(a)", "Estagiário(a)", "Controladoria", "Financeiro", "Administrativo", "Outra"), ou NULL. Enum
  `App\Profile\Enum\Atuacao`. Advogado e estagiário vêm do pedido do dono; os outros quatro existem porque o filtro
  Cargo do desenho os tem.
- CHECK nos dois. Sem backfill: quem já existe fica NULL, para o filtro só contar o que a pessoa declarou. NULL e
  `nao_informado` querem dizer "sem essa informação no cadastro (fica de fora)" (desenho 05, L636).
- Preenchidos pela própria pessoa no modal "Editar informações" do Meu Perfil (campos GÊNERO e ATUAÇÃO depois de
  NASCIMENTO), nunca obrigatórios. Não aparecem na grade de dados (o desenho 07 não os tem).
- Sugestão (`App\Profile\Service\SugestaoDeAtuacao`): com a atuação vazia, o modal mostra "Sugestão do sistema:
  {rótulo}. Usar" debaixo do campo, que continua VAZIO; "Usar" só marca a opção, e a pessoa grava ao salvar. A OAB
  principal confirmada (cópia no User) sugere advogado; o vínculo de estágio no escritório da sessão sugere
  estagiário; a OAB vence o estágio. Decisão da revisão (21/10): no começo a sugestão vinha marcada, e quem abria o
  modal só para trocar o telefone gravava a atuação sem perceber, o que contraria "o filtro só conta o que a pessoa
  declarou".
- Papel só para leitura (b5: desenho 07 L58, L205, L233, "vêm da conta"): "Usuário primário de {escritório}" ou
  "Secundário de {escritório}" junto do cargo e da lotação, no escritório da sessão, sem campo.
- A tela de dados pessoais do admin não edita gênero nem atuação e não os apaga ao salvar (mesmo cuidado da frase e
  do telefone).

## Leitor para a 5d

`UserProfileRepository::atributosDe(list<int> $userIds): array<int, array{genero: ?string, atuacao: ?string,
dataNascimento: ?\DateTimeImmutable, uf: ?string}>`: uma consulta só, indexada pelo userId, com as strings cruas do
enum. `uf` é a UF da OAB principal (cópia no User), em maiúsculas. Quem não tem perfil vem com tudo null; id que não
existe fica de fora. Sem TenantFilter (o perfil é da pessoa, não do escritório).
