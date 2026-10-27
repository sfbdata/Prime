# Spec: chave PIX para recompensas no Meu Perfil (desenho 07)

Risco: MÉDIO (Profile; dado financeiro da pessoa, mas nenhum dinheiro se move por este campo). Pedido da coordenação
(27/10), item ✗ do `status-desenhos-27-10.md`: o desenho "07 - Meu Perfil - VS 18.10.26" tem o campo "Chave PIX para
recompensas" em "Editar informações" e o sistema não.

## Desenho (o que manda)

- Modal "Editar informações" (`ED.info`), logo depois de TELEFONE / WHATSAPP e antes de CPF: rótulo `CHAVE PIX PARA
  RECOMPENSAS`, campo de texto, placeholder `CPF, e-mail, celular ou chave aleatória`, uma coluna (metade da largura,
  como o telefone). Chave do formulário no desenho: `pixRecomp`; o salvar faz `trim` e grava texto vazio como vazio.
- O desenho NÃO mostra a chave na grade de dados do perfil, nem na lista de revisão do cadastro, nem no percentual de
  completude. Então ela só aparece no modal de edição. O que o desenho não tem não entra.

## O que muda

- Coluna nova `user_profiles.chave_pix_recompensa VARCHAR(140) NULL` (mesmo tamanho de `chave_pix` dos dados de
  recebimento da F4). Sem backfill: quem já existe fica nulo. Migration `Version20261027153900` (ALTER TABLE ADD COLUMN
  nula, sem UPDATE/DELETE).
- `UserProfile::getChavePixRecompensa()/setChavePixRecompensa(?string)`; `DadosPessoaisInput::$chavePixRecompensa`;
  `DadosPessoaisType` (campo opcional, `maxlength` 140, placeholder do desenho); `AtualizarDadosPessoaisUseCase` grava
  com `textoOuNulo` (aparado; vazio vira nulo).
- Validação no DTO (`#[Assert\Length(max: 140)]` e um callback do grupo `perfil`): vazio vale; senão precisa ser uma
  chave PIX reconhecível, de um dos cinco tipos: CPF válido (dígitos verificadores), CNPJ válido, e-mail, celular
  (10 ou 11 dígitos, com ou sem `+55`, formato aceito pelo PIX `+55DDDNÚMERO`) ou chave aleatória (UUID). O desenho cita
  os quatro primeiros; o CNPJ é chave PIX válida e o campo não deve recusá-lo (omissão do desenho, vale a convenção do
  sistema). Mensagem única: "Informe uma chave PIX válida: CPF, CNPJ, e-mail, celular ou chave aleatória."
- A chave é gravada como digitada, aparada. CPF, CNPJ e celular seguem só com dígitos no que o usuário digitou (sem
  normalização silenciosa que mude o que a pessoa vê ao reabrir o modal).
- A tela de dados pessoais do admin (`DadosPessoaisType` sem os campos do perfil) NÃO edita nem apaga a chave (mesmo
  cuidado da frase, do telefone e do gênero): o campo mora só no bloco `adicionarCamposDoPerfil`.

## Privacidade

- Só a própria pessoa lê e edita a chave no Meu Perfil. Não entra em `PerfilOutput` para a grade, nem em
  e-mail, notificação, log ou resposta de listagem de equipe: o modal a recebe pelo `DadosPessoaisInput::dePerfil`.
- Pode ser um CPF ou telefone: dado pessoal (LGPD). `UserProfile` não é `Auditavel`, então a trilha de auditoria não a
  copia. Se um dia virar, o campo entra no `IGNORED_FIELDS_POR_CLASSE` do `AuditLogSubscriber` (mesma regra do documento
  e da chave PIX da F4).
- A exclusão da conta apaga junto (é coluna do perfil).

## Fora desta fatia

- Ligação com o Master: as Recompensas (F4, "Dados para pagamento") têm a chave PIX de quem recebe, cadastrada pelo
  Master. Esta fatia não faz o Master ler a chave do perfil. Como fatia seguinte (depende do #475 mergeado): o diálogo
  "Dados para pagamento" mostra "Chave informada pelo indicador: X" quando existe e a do Master está vazia, com um
  botão "Usar", e o Master confirma. Nunca é preenchida nem paga sozinha. Pergunta ao dono: quer essa fatia?
- Validação de existência da chave junto ao banco (DICT): não existe no sistema.
- Chave na grade de dados do perfil: o desenho não tem.

## P2 (Master vê a chave do perfil como sugestão; decidido em 27/10, depois do #475)

- Só o Master. O GET `/master/recompensas/usuarios/{id}/recebimento` (já restrito a ROLE_SUPER_ADMIN) ganha o campo
  `chavePixDoPerfil` (a chave do `UserProfile` do indicador, ou nulo), mesmo quando o Master ainda não cadastrou os
  dados de recebimento (a resposta passa de `{}` para `{chavePixDoPerfil: ...}`). A lista do painel (`recebimento`
  em cada indicador) NÃO traz a chave do perfil.
- No diálogo "Dados para pagamento", se a chave do Master está vazia e o indicador informou uma, aparece abaixo do campo
  "Chave informada pelo indicador: X · Usar". "Usar" só copia para o campo; o Master confere e clica em "Salvar dados".
  Nada é preenchido, salvo ou pago sozinho, e a sugestão nunca sobrescreve a chave que o Master já tem.
- Sem migration. Texto novo em `master.recompensas.recebimento.pix_sugestao` e `pix_usar`.

## Testes (antes do código)

- Unit do DTO: vazio vale; CPF e CNPJ válidos e inválidos; e-mail; celular com e sem `+55`; UUID; texto solto recusa;
  141 caracteres recusa.
- Unit do UseCase: grava aparada; vazia vira nula; trocar e limpar; não toca nas outras colunas.
- Functional do Meu Perfil: o modal traz o campo com o placeholder do desenho; POST válido grava; POST inválido devolve
  a mensagem e não grava; a grade e a revisão do cadastro não mostram a chave; a tela de dados do admin não a apaga.
- `SemTravessaoNovoArquiteturaTest` e tradução: textos novos sem travessão, em `perfil.editar.pix_recompensa` (rótulo,
  placeholder, erro) no catálogo do perfil.

## Fatias

| Fatia | Conteúdo | Estado |
|---|---|---|
| P1 | Coluna, DTO, form, UseCase, modal e testes | a fazer |
| P2 | Master vê a chave do perfil como sugestão nos Dados para pagamento | decidido; sem migration |
