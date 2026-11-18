# Acesso restrito às pastas sob responsabilidade

Perfil para equipe comercial, prospectadores e parceiros externos. O usuário com esse perfil só vê, abre, busca, baixa e movimenta as pastas em que está cadastrado como responsável. Os perfis atuais não mudam.

## 1. Regras de negócio

1. Toda pasta nova recebe como responsável quem a criou (abertura manual e importação em lote). Pastas antigas sem responsável continuam como estão; só ficam visíveis para perfis sem restrição.
2. No perfil secundário existe a opção "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE", desligada por padrão. Ligada:
   - o escopo de pastas do usuário passa a ser `responsavel_id = usuario_id`;
   - itens administrativos ficam bloqueados (Administração do escritório inteira, Outros recursos, e os módulos: Painel do Escritório, Push Processual, Demandas, Minhas Metas, Agenda, Peticionar, Financeiro, FinBlue, Gestão de Cobranças, Trabalho Externo, BlueJus IA, Kanban, Ponto, Service Desk, Tarefas, BI);
   - os recursos Pasta, Documento, Cliente e Processo continuam configuráveis, mas valem só dentro das pastas do usuário (documentos, clientes e processos dessas pastas).
3. Identificação do perfil (Prospectador, Parceiro, Equipe comercial, Correspondente, Colaborador externo) aparece em destaque na lista de perfis e no cadastro do usuário.
4. O usuário restrito não transfere a responsabilidade da pasta para outra pessoa. A transferência é feita por usuário sem restrição e registrada na auditoria; ao transferir, o restrito perde o acesso na hora.
5. Cada acesso a pasta e cada tentativa negada ficam na trilha de auditoria (usuário, pasta, rota, IP, data e hora).

## 2. Aplicação em todas as camadas (obrigatório no servidor)

Esconder na tela não basta. A regra fica no backend, num único ponto, e toda consulta passa por ele.

- **Modelo**: `perfil.escopo_pasta` = `'todas'` (padrão, comportamento atual) ou `'responsavel'`; `perfil.rotulo` (texto). `pasta.responsavel_id` obrigatório na criação (preenchido com o usuário logado).
- **Repositório / consulta**: um filtro central (ex.: Doctrine SQL Filter ou escopo global do ORM) acrescenta `AND pasta.responsavel_id = :usuario` em toda consulta de pasta quando o perfil do usuário logado tiver `escopo_pasta = 'responsavel'`. Entidades filhas (documento, processo, prazo, meta, movimentação, cliente vinculado) são consultadas sempre por JOIN com a pasta, herdando o filtro.
- **Autorização por objeto**: Voter/Policy `PastaVoter::VIEW/EDIT/DOWNLOAD` nega quando `escopo_pasta = 'responsavel'` e `pasta.responsavel_id != usuario`. Vale para rotas por ID (`/pasta/{nup}`, `/documento/{id}/download`, `/processo/{id}`), evitando acesso por URL digitada.
- **Busca e contagens**: busca global, autocompletar, filtros, totais, paginação e indicadores usam o mesmo filtro; nenhum total do escritório é exibido.
- **Downloads e exportações**: CSV, PDF, ZIP, relatórios e boletins geram só com as pastas do usuário.
- **API e integrações**: endpoints REST, webhooks e tokens de API aplicam o mesmo filtro; tokens emitidos para usuário restrito herdam o escopo.
- **IA e agentes**: a BlueJus IA fica desligada para o perfil restrito até que todo contexto enviado à IA passe pelo mesmo filtro.
- **Notificações e e-mails**: só de pastas do usuário.
- **Cache**: chaves de cache incluem o usuário (ou o escopo) para não servir lista de outro perfil.
- **Testes automatizados**: para cada rota acima, teste que um usuário restrito recebe 403/404 ao pedir pasta de outro responsável e que listas e totais não incluem pastas alheias.

## 3. Compatibilidade

- Perfis existentes ficam com `escopo_pasta = 'todas'`; nada muda para eles.
- Migração: adicionar as colunas com padrão `'todas'`; não alterar pastas, documentos nem permissões já gravadas.
- O responsável automático na criação vale para todos os perfis daqui em diante.

## 4. Protótipo

- Perfis: cartão "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE" no editor do perfil secundário, identificação do perfil, itens bloqueados com cadeado, selo na lista e botão "Simular acesso".
- Simulação (só no protótipo, `bj-escopo.js`): a lista de pastas mostra só as pastas do usuário simulado; a pasta de outro responsável e as demais telas mostram "Fora do seu acesso"; faixa "Simulação" com "Sair da simulação".
- No protótipo o filtro roda no navegador apenas para demonstrar o comportamento. No sistema real ele roda no servidor.

## Compartilhar pasta (modo único: equipe ou outra conta)

Um só fluxo, pelo ícone de compartilhar na linha da pasta (mesmo conceito do "republicar"):

- Equipe do escritório: escolhe o usuário; vale na hora, sem aceite. É o mesmo mecanismo usado para dar ao parceiro ou prospectador (acesso restrito) uma pasta da qual ele não é o responsável.
- Outra conta: informa o código pessoal BJ-XXXX-XXXX-XXXX; o destinatário aceita em "Recebidas" e a pasta aparece na conta dele com o ícone de compartilhada. Uma conta recebe pastas de várias outras, sem precisar de login em cada escritório.
- Permissões: ver pasta, documentos e metas (sempre); abrir e movimentar metas, andamentos e prazos; enviar documentos; baixar documentos. Validade opcional. O dono encerra o acesso quando quiser; o colaborador pode deixar a pasta.

Regra de acesso no servidor (toda rota da pasta, metas, documentos, buscas, contagens, downloads e relatórios):

    pode_acessar(usuario, pasta) =
         pasta.responsavel_id = usuario.id
      OR EXISTS (pasta_compartilhamento c
                 WHERE c.pasta_id = pasta.id AND c.usuario_destino_id = usuario.id
                   AND c.status = 'aceita' AND (c.validade IS NULL OR c.validade >= hoje))
      OR (perfil sem escopo restrito AND pasta.conta_id = usuario.conta_id)

Cada ação é conferida contra c.permissoes. O compartilhamento nunca abre o restante da conta de origem.

Tabela: pasta_compartilhamento (id, pasta_id, conta_origem_id, usuario_origem_id, usuario_destino_id, interno, permissoes[], validade, status, mensagem, criado_em, aceito_em, encerrado_em, encerrado_por).

## Troca de responsável

Pede confirmação. O responsável anterior é desvinculado e, se tiver acesso restrito, perde o acesso à pasta e às metas dela, salvo se o usuário marcar "Manter como colaborador" (gera um compartilhamento interno). A troca entra no histórico da pasta.
