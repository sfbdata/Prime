# Configurações globais com efeito: leitor e cadastro aberto, por convite ou fechado

Risco ALTO (Auth: criação de conta, identidade User/Tenant). O PR #410 grava a `ConfiguracaoGlobal` (desenho 05 Perfis
1.2 VS 25.10.26) e nada a consome. Esta frente liga as configurações uma a uma, um PR por configuração, nesta ordem
(decisão do coordenador, 26/10, e do dono, 27/10): **1. cadastro (este PR)**, 2. tentativas de login, depois bloqueio de
conta, nome nos templates, sessão e 2FA (cada um com spec e aviso próprio; 2FA só com spec própria).

## Leitor (base de todas as configurações)

- Contrato `App\Shared\Contract\ConfiguracaoDaPlataforma` (`valor(string $chave): string`), implementado em
  `App\Master\Service` sobre a `ConfiguracaoGlobalRepository`. Os outros domínios (Auth) dependem só do contrato, não
  de internals do Master.
- Valor = o gravado, senão o padrão do `CatalogoDeConfiguracoes`. **Nunca derruba nada**: qualquer falha de leitura
  (banco, linha ausente, chave desconhecida) cai no padrão. Cache só dentro da requisição (a leitura é de uma linha e só
  as telas e rotas de entrada leem; sem cache entre requisições, para a mudança do Master valer na hora).
- Só leem as chaves do catálogo; o leitor não grava.

## Cadastro (`cadastro` = `on` | `convite` | `off`, padrão `on`)

Mesmo texto das opções do Master: Aberto, Só por convite, Fechado.

1. **Aberto (`on`)**: como hoje.
2. **Só por convite (`convite`)**: nenhuma conta nasce pelo cadastro público (e-mail, Google, Facebook). Contas
   continuam nascendo por convite (de escritório, da plataforma e do Trabalho Externo). Login de quem já tem conta
   (senha, Google, Facebook) não muda.
3. **Fechado (`off`)**: também não nascem contas novas por convite de escritório nem do Trabalho Externo; o convite da
   **plataforma** (emitido pelo Master) continua, é a forma de o Master abrir uma conta com o cadastro fechado. Aceitar
   convite com uma conta que já existe (vincular) continua. Login não muda. (Interpretação provisória do coordenador;
   o dono revê na tela.)
4. **Ponto de bloqueio:** `CriarContaEEscritorioUseCase` nas três portas públicas (`executar`, `executarPeloGoogle`,
   `executar`, que também recebe o `facebook_id`) consulta o leitor no começo e lança `CadastroNaoDisponivel`
   antes de qualquer gravação, e os fluxos de convite que criam conta nova (`AceitarConviteEscritorioSemContaUseCase`,
   e o aceite do Trabalho Externo que cria conta) consultam no começo se a política é `off`. Os controllers respondem 403
   com a mensagem da política (JSON `{erro: ...}` no modal, sem criar nada, sem vazar se o e-mail existe).
5. **Tela de entrada:** com `convite` ou `off`, o link "Criar conta" (`#lgCriar`), o passo `cad` e os botões
   sociais do cadastro não aparecem; `?abrir=cadastro` e `/cadastro` (e o `/i/{codigo}`, que leva a ele) abrem o login
   com o aviso da política no lugar do cadastro (texto por tradução, sem travessão): "O cadastro está disponível só por
   convite." / "O cadastro está fechado no momento." O cartão de login não muda.
6. **Google/Facebook sem conta** com o cadastro fechado ao público: a volta do provedor não guarda a identidade nem
   abre o cadastro; volta ao login com o mesmo aviso. Login de conta existente pelo provedor não muda.
7. **Segurança do Master:** a política nunca bloqueia login nem a rota do Master (`/master`). Padrão `on`: sem linha
   gravada, tudo como hoje.
8. Cupom e bônus (PR #395/#416): sem efeito quando o cadastro está bloqueado (nenhuma conta nasce).

## Testes

- Unit do leitor: gravado vence o padrão; sem linha, padrão; chave desconhecida e exceção do repositório, padrão (sem
  lançar).
- Unit do `CriarContaEEscritorioUseCase` (3 portas) e dos UseCases de convite: política `on` cria; `convite` bloqueia as
  portas públicas e deixa o convite; `off` bloqueia as portas públicas e o convite de escritório/Trabalho Externo, mas
  não o da plataforma; nada é gravado quando bloqueia.
- Funcional: `/cadastro/criar`, `/cadastro/google/criar`, `/cadastro/facebook/criar` respondem 403 neutro com `convite`
  e `off`; login com senha, Google e Facebook de conta existente seguem; a tela de login esconde o cadastro e mostra o
  aviso; `/cadastro` e `/i/{codigo}` caem no login com o aviso; o convite da plataforma cria conta com `off`.
- Mudar a configuração no Master (`SalvarConfiguracaoGlobalUseCase`) vale na requisição seguinte.

## Fora deste PR

Tentativas de login, sessão, 2FA, nome e remetentes (PRs próprios). Sem migration.

Ficam fora da política, por não criarem conta nova: `ConfirmarCadastroUseCase` (legado de
`CadastroPendente`, sem produtor vivo, expira em 24h) e `CriarSenhaPeloLinkUseCase` (conta criada
com cadastro aberto que ainda define a senha depois de o cadastro fechar).
