---
tipo: novidade
---
## Changelog
- Assinatura de documentos, etapa 4 (docs/specs/assinatura-documentos-etapa-4.md): modelos do escritório. Aba Modelos com "Criar novo modelo", Editar, Excluir (com confirmação) e "Duplicar e adaptar" nos prontos; editor com nome, categoria, formato, folha branca, texto lateral, barra de formatação, "Inserir campo", "Criar campo", lugar de assinatura, Importar Word e prévia ao vivo; "Criar novo modelo" também no passo 1 do assistente, e o modelo do escritório entra no passo 1 junto dos prontos.
- Tabela nova `assinatura_eletronica_modelo` (migration `Version20261109260000`), com escritório, autor e datas; entra na purga do escritório. Rotas JSON `GET/POST /assinatura/modelos`, `POST /assinatura/modelos/{id}` e `POST /assinatura/modelos/{id}/excluir` (módulo, CSRF e 404 para modelo de outro escritório).
- Nenhum HTML vai ao servidor: o navegador converte o editor em campos, papéis de quem assina e blocos, e o servidor confere tudo (`ValidadorDoModelo`) e recusa com o texto da regra (422), sem cortar nada. O documento guarda a chave `esc-<id>` e os próprios blocos: excluir o modelo não muda o que já foi enviado.

## Para o cliente
- Na Assinatura de documentos, o escritório pode criar os próprios modelos: escrever o texto, marcar os campos que mudam em cada documento (nome do cliente, CPF, data) e onde cada pessoa assina, ou partir de um modelo pronto ou de um arquivo do Word. Os modelos ficam disponíveis para toda a equipe do escritório. A tela continua desligada e só aparece para a equipe do BlueJus; avisaremos quando estiver liberada.
