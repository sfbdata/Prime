---
name: criar-form
description: "Padrões para criar ou refatorar Symfony Form Types do jusprime: estrutura, data_class apontando para Input DTO, CSRF, uploads. Carregue ao criar, editar ou revisar arquivos *Type.php em app/src/<Dominio>/Form/."
---

# Form/ — Regras de Symfony Form Types

## Responsabilidade

Form Type = define campos, tipos e transformações de um formulário HTML.
Form NÃO valida regras de negócio — isso é do DTO com `#[Assert\...]`.

## Estrutura obrigatória

```php
<?php
declare(strict_types=1);
namespace App\<Dominio>\Form;

use App\<Dominio>\DTO\Criar<Nome>Input;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class <Nome>Type extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome completo',
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
            ]);
        // botões ficam no template, não aqui (exceto multi-submit)
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Criar<Nome>Input::class,
        ]);
    }
}
```

## Regras

- Nome: `<Nome>Type` — sufixo obrigatório
- Sempre `final`
- `data_class` sempre aponta para o **Input DTO**, nunca para a entidade Doctrine
- Botões de submit ficam no **template Twig**, não na classe Form — exceção: forms com múltiplos submits com ações diferentes
- CSRF ativado por default — **não desativar** sem motivo documentado

## Controller com Form (padrão uma única action)

```php
#[Route('/clientes/criar', name: 'app_cliente_criar', methods: ['GET', 'POST'])]
public function criar(Request $request): Response
{
    $dto = new CriarClienteInput();
    $form = $this->createForm(ClienteType::class, $dto);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $this->criarClienteUseCase->executar($dto, $this->getUser());
        $this->addFlash('sucesso', 'Cliente criado com sucesso.');

        return $this->redirectToRoute('app_cliente_listar');
    }

    return $this->render('cliente/criar.html.twig', [
        'form' => $form,
    ]);
}
```

## Uploads

Em forms com upload de arquivo:
- Validar mimetype + extensão + tamanho antes de persistir (no UseCase ou via `#[Assert\File]` no DTO)
- Nunca salvar o arquivo diretamente no controller
- Persistir pelo armazenamento por chave: `FonteDeUploadHttp::de($arquivo)->gravarEm($armazenamento, ChavesDe<Dominio>::novo…(...))`
  (padrão completo em `app/src/Shared/CLAUDE.md`, seção "Arquivos — endereçados por CHAVE"). O antigo
  `ArquivoStorageService` saiu na E2.8

## Tamanho e organização de método

- **Meta: ~20 linhas por método.** O CI reprova acima de 40 (`tests/Arquitetura/MetodoLongoArquiteturaTest`,
  contado do `function` ao `}`; docblock e atributos não contam). 40 é o limite, não a meta.
- **Um método, um nível de abstração.** O método público conta a história chamando métodos privados com
  nome do negócio; o detalhe mora no privado.
- **Bloco que precisa de comentário para ser entendido vira método**, e o comentário vira o nome dele.
- **Método legado longo** (está em `tests/Arquitetura/metodos-longos-tolerados.json`): pode crescer no
  máximo 5 linhas sobre o número da lista, no total. O código novo vai para um método privado novo; não
  reescreva o legado sem liberação. Mover para o domínio é trocar a chave no JSON com o mesmo número.
- `buildForm()` com muitos campos se divide em um privado por grupo (`adicionarCamposDeEndereco($builder)`).

## `make:form` — não use

Gera em `src/Form/` (não em `app/src/<Dominio>/Form/`) e, quando apontado para uma entidade, monta o
`data_class` na **entidade** — o oposto da regra desta camada, que exige `data_class` no **Input DTO**.
Escreva à mão a partir do esqueleto acima.

Comando útil de verdade nesta camada: `debug:form` (lista os tipos de form disponíveis e as opções de
cada um) e `debug:form <Tipo>` para um específico.
