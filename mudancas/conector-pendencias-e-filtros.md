---
tipo: novidade
---
## Changelog
- Conector de I.A. (`ler_clientes`): ação nova `pendencias`, só leitura. Lista numa chamada os clientes a que falta um campo do cadastro (`faltando`: cpf, rg, endereco e os demais campos; sem ele, CPF ou CNPJ), com id, nome, tipo, campos que faltam e as pastas vinculadas que a pessoa abre (id e número); visão `pastas_sem_cliente` lista as pastas ativas sem cliente vinculado. Página de 100 (até 200) e o total do filtro. Valores de CPF, RG, nascimento, e-mail, telefone e endereço nunca saem; pasta em segredo de justiça sai e é contada; pasta trancada ou sem acesso não aparece.
- Conector de I.A. (`ler_documentos_e_modelos`, ação `listar`): filtros opcionais `categoria`, `nome` (trecho, sem ligar para maiúsculas e acentos), `tipo` (extensão ou mime) e `subpasta_id` (0 = fora das subpastas), `total` do filtro e `total_na_pasta`; modo `compacto` (id, nome e categoria) com 100 por página, até 200.
- Conector de I.A. (`buscar_pastas`, ações `buscar` e `minhas_demandas`): o campo `clientes` vinha vazio em toda pasta com cliente vinculado (desde 27/10/2026 o identificador da Lista é o cliente principal, e a lista de nomes só saía sem identificador). Agora traz os nomes de todos os vínculos, o principal primeiro, com `clientes_ids` e `cliente_principal`, iguais ao resumo da `ler_pasta`; lista vazia só quando a pasta não tem cliente.
- Conector de I.A. (`ler_pasta`, resumo): com as abas Dados e Processo ocultas, os processos vêm como "sem acesso" (`total` nulo e `sem_acesso`), não mais como "nenhum processo vinculado".

## Para o cliente
- O assistente de I.A. conectado ao BlueJus agora responde numa pergunta só quais clientes estão sem CPF (ou sem outro dado do cadastro) e em quais pastas estão, e quais pastas ativas estão sem cliente vinculado. Os números de documento e os contatos continuam sem sair do sistema.
- O assistente também filtra os documentos de uma pasta por categoria, nome, tipo de arquivo e subpasta, e lista mais documentos de uma vez.
- Na busca de pastas pelo assistente, o cliente vinculado aparece em todas as pastas que têm cliente (antes podia vir em branco, como se a pasta não tivesse cliente).
