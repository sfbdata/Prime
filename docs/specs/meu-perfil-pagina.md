# Spec: página Meu Perfil (PR 2 do redesenho, VS 18.10.26)

Risco: MÉDIO (dados do vínculo da pessoa com o escritório; a data de admissão aparece no espelho de ponto).
Desenho: `docs/design/meu-perfil-18-10/07 - Meu Perfil - VS: 18.10.26.dc.html` (linhas 52 a 118, 123 a 175 e o
script das linhas 179 a 272). Onde o desenho mostra, ele manda; onde é omisso, vale a convenção do sistema.

## 1. O que a tela mostra

1. **Identificação**: capa com o logo BlueJus à direita; avatar 104px com iniciais (primeiro e último nome) ou
   foto e o selo de câmera; nome em caixa alta; selo ★ MASTER (ROLE_SUPER_ADMIN) e selo do papel
   (USUÁRIO PRIMÁRIO ou SECUNDÁRIO); cargo e lotação do vínculo; botão "Editar informações"; a frase do perfil
   (ou "Adicione uma frase ao seu perfil" em itálico).
2. **Cadastro incompleto** (só abaixo de 100%): percentual, barra, chips do que falta e "Completar agora".
   Itens contados: foto, telefone, CPF, nascimento, frase, OAB e, conforme o vínculo, CTPS (CLT) ou início do
   contrato (demais). Percentual = (preenchidos + 2) / (itens + 2), arredondado (nome e e-mail sempre contam).
3. **Grade de dados** (rótulo "Não informado" em cinza quando vazio; copiar no hover para e-mail, CPF, CNPJ e
   telefone): E-mail, Vínculo ("Empregado CLT · bate ponto" etc.), CPF; conforme o vínculo:
   CLT = CTPS, Série, Admissão; Estagiário = Início do estágio; PJ = CNPJ, Início do contrato;
   Associado e Sócio = Início do contrato; depois Nascimento, Membro desde, Telefone.
4. **Preferências** ("Valem só para você"): Idioma (abre o seletor do PR 1), Notificações no sistema,
   Sons de alerta e Resumo diário por e-mail (chaves liga/desliga, padrão ligado).
5. **Segurança**: o card que já existe, com o texto do desenho.
6. **Revisão mensal**: uma vez por mês o pop-up "Seu cadastro está atualizado?" com o percentual e o resumo
   (Telefone, Vínculo, OAB principal). "Está tudo certo" grava a data da confirmação; "Atualizar agora" abre
   o modal. O "já mostrei neste mês" fica no navegador (conveniência de tela); a confirmação, no servidor.

Fora deste PR: várias inscrições na OAB (PR 3), Excluir conta (PR 5). O card da OAB continua o atual.

## 2. Modal "Editar informações"

- **Vínculo com o escritório** (5 botões: Empregado CLT, Associado, Prestador / PJ, Sócio, Estagiário) com a
  ajuda de cada um. **Só o primário do escritório (ou o Master) altera**; o secundário vê os botões travados
  e "Definido pelo escritório. Para alterar, fale com o usuário primário." O servidor ignora o vínculo vindo
  de quem não pode alterar.
- Nome completo, Frase do perfil, Telefone / WhatsApp, CPF (11 dígitos se informado), Nascimento.
- Conforme o vínculo escolhido: CLT = CTPS, Série, Data de admissão; PJ = CNPJ (se houver) e Início do contrato;
  Estagiário = Início do estágio; Sócio = Entrada na sociedade; Associado = Início do contrato.
- Vínculo ausente é tratado como Empregado CLT (padrão do desenho).
- Bate ponto: CLT e Estagiário. Só rótulo nesta entrega: nenhuma regra do módulo Ponto muda.

## 3. Dados

| Dado | Onde | Por quê |
|---|---|---|
| telefone, revisado_em | `user_profiles` | dado da pessoa, vale em qualquer escritório |
| tipo do vínculo, início do contrato, CNPJ | tabela nova `vinculo_no_escritorio` (tenant, user, único) | é do vínculo com AQUELE escritório; tabela própria no domínio Profile em vez de crescer a entidade legada `UserTenant` |
| data de admissão | `user_tenant.data_admissao` (já existe) | o espelho de ponto já lê daí |
| preferências | `preferencia_usuario` (já existe), chaves `perfil.notificacoes`, `perfil.sons`, `perfil.resumo_email` | catálogo fechado do Profile |

Isolamento: `vinculo_no_escritorio` é TenantAware; a leitura e a gravação partem do escritório da sessão.

## 4. Rotas

- `POST /perfil/dados` (já existe): passa a gravar os campos novos.
- `POST /perfil/preferencia` (JSON `{chave, ligado}`, CSRF do header AJAX): grava uma preferência do catálogo;
  chave fora do catálogo responde 400.
- `POST /perfil/revisao` (CSRF): grava a confirmação mensal.

## 5. Pergunta em aberto (enviada ao coordenador)

O desenho deixa o secundário editar a própria data de admissão e início do contrato (só o vínculo fica
travado). A admissão sai no espelho de ponto. Implementado como no desenho; se o dono preferir travar
também as datas para o secundário, é uma condição só no UseCase.
