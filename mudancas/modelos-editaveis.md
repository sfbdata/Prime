---
tipo: novidade
---
## Changelog
- Comercial do Master: "Salvar modelo" passa a valer também nos modelos padrão do sistema (boas-vindas, comerciais e as 17 mensagens das datas comemorativas). A versão do Master fica em `comercial_modelo` com `fixo_chave` (uma linha por chave, fora de "Meus modelos") e é a que a grade mostra; "Voltar ao padrão" (POST `master_comercial_modelo_padrao`, trilha `modelo_pad`) apaga a versão e devolve o texto do sistema.
- Datas comemorativas: novo seletor "Modelo" em cada data (`comercial_data_comemorativa.modelo_chave`; vazio é o "Padrão da data"). O disparo usa o modelo escolhido, na versão editada se houver; se o modelo próprio escolhido foi excluído, vale o padrão da data e o log avisa. As campanhas das datas passam a ser reconhecidas pela chave de idempotência `data:`, e não mais pelo modelo.
- Migration `Version20261123200000`: `comercial_modelo.fixo_chave` (único) e `comercial_data_comemorativa.modelo_chave`.

## Para o cliente
- No Painel do Master, as mensagens de boas-vindas e as comerciais agora podem ser editadas mesmo quando são as que vêm prontas com o sistema. Basta mudar o texto e clicar em "Salvar modelo". Para desfazer, o botão "Voltar ao padrão" traz de volta o texto original.
- Nas datas comemorativas, cada data ganhou a escolha do modelo da mensagem: dá para usar o texto padrão da data, outro modelo do sistema ou um modelo seu.
