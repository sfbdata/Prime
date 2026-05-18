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
- Usar `ArquivoStorageService` (ou `ArquivoStorageInterface`) para persistência do arquivo
