<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\CriarObjetoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Cadastrar um Objeto de Cobrança numa Carteira (SPEC §4/§5). `carteiraId` NÃO é campo — vem da rota e
 * é preenchido pelo controller. Validação nos #[Assert] do DTO; a normalização dos textos ocorre no
 * UseCase.
 */
final class CriarObjetoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('identificacao', TextType::class, [
                'label' => 'Identificação',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: Apto 402, placa, matrícula', 'maxlength' => 255],
            ])
            ->add('nomeCobrado', TextType::class, [
                'label' => 'Nome de quem será cobrado',
                'help' => 'Só o nome. Você completa CPF, telefone e e-mail depois, na aba Pessoas do objeto.',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: João da Silva', 'maxlength' => 255],
            ])
            ->add('descricao', TextareaType::class, [
                'label' => 'Descrição (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 2],
            ])
            ->add('referenciaExterna', TextType::class, [
                'label' => 'Referência externa (opcional)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CriarObjetoInput::class,
        ]);
    }
}
