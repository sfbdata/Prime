<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\CriarPessoaVinculadaInput;
use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Nova pessoa" dentro do objeto (ajuste 2): cadastra a pessoa e a vincula ao objeto num passo só.
 * `objetoId` vem da rota. Só o nome é obrigatório; os demais dados são opcionais. Validação nos
 * #[Assert] do DTO.
 */
final class CriarPessoaVinculadaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('tipoVinculo', EnumType::class, [
                'label' => 'Tipo de vínculo',
                'class' => TipoVinculo::class,
                'choice_label' => static fn (TipoVinculo $t): string => $t->label(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('cpf', TextType::class, [
                'label' => 'CPF (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('cnpj', TextType::class, [
                'label' => 'CNPJ (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('telefone', TextType::class, [
                'label' => 'Telefone (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CriarPessoaVinculadaInput::class,
        ]);
    }
}
