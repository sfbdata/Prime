---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, 14.a): o Master deixa de liberar o conector escritório por escritório (decisão do Samuel, 19/11). O administrador do escritório liga o conector e dá o aceite sozinho, sem depender de liberação. A chave geral `conector_ia` do catálogo de recursos continua como interruptor de emergência (nasce ligada) e corta tudo quando desligada. Saem a coluna `conector_ia_escritorio.liberado_pelo_master` (migration `Version20261119100000`), o UseCase `AlterarLiberacaoDoConectorPeloMasterUseCase` e a rota `master_conector_escritorio`. A aba "Configurações da I.A." do Master mantém só a tabela de leitura (ligado, aceite e contagem de conexões). O comando `conector:liberar` continua, agora só liga o escritório e registra o aceite. O cartão do administrador ganhou o estado "fora do ar" para quando a chave geral estiver desligada.

## Para o cliente
- O administrador do escritório agora liga o conector de I.A. por conta própria, na tela BlueJus IA: marca "Li e aceito" e clica em Ligar. Não é mais preciso esperar a liberação da equipe BlueJus.
- Depois de ligado, cada pessoa da equipe conecta o Claude ou o ChatGPT à própria conta, seguindo o passo a passo do botão "Como conectar" no Meu Perfil.
- Se a BlueJus precisar tirar o conector do ar por um motivo de segurança, a tela BlueJus IA avisa que ele está fora do ar por enquanto. Quando voltar, o que o escritório já ligou volta a valer, sem refazer nada.
