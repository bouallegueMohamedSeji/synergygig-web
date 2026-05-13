<?php

namespace App\Form;

use App\Entity\Interview;
use App\Entity\Offer;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class InterviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organizer', EntityType::class, [
                'class' => User::class,
                'choice_label' => function (User $u) {
                    return $u->getFirstName() . ' ' . $u->getLastName();
                },
                'placeholder' => 'Select organizer',
                'constraints' => [new Assert\NotBlank(['message' => 'Please select an organizer.'])],
                'attr' => ['class' => 'form-control form-select'],
                'label_attr' => ['class' => 'form-label'],
            ])
            ->add('candidate', EntityType::class, [
                'class' => User::class,
                'choice_label' => function (User $u) {
                    return $u->getFirstName() . ' ' . $u->getLastName();
                },
                'placeholder' => 'Select candidate',
                'constraints' => [new Assert\NotBlank(['message' => 'Please select a candidate.'])],
                'attr' => ['class' => 'form-control form-select'],
                'label_attr' => ['class' => 'form-label'],
            ])
            ->add('date_time', DateTimeType::class, [
                'widget' => 'single_text',
                'label' => 'Date & Time',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please select a date and time.']),
                    new Assert\GreaterThanOrEqual(['value' => 'today', 'message' => 'Interview date cannot be in the past.']),
                ],
                'attr' => ['class' => 'form-control'],
                'label_attr' => ['class' => 'form-label'],
                'setter' => function (Interview &$interview, ?\DateTimeInterface $value): void {
                    if ($value !== null) {
                        $interview->initDate_time($value);
                    }
                },
            ])
            ->add('offer', EntityType::class, [
                'class' => Offer::class,
                'choice_label' => 'title',
                'placeholder' => 'Select offer',
                'constraints' => [new Assert\NotBlank(['message' => 'Please select an offer.'])],
                'attr' => ['class' => 'form-control form-select'],
                'label_attr' => ['class' => 'form-label'],
            ])
            ->add('meet_link', TextType::class, [
                'label' => 'Meeting Link',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please provide a meeting link.']),
                    new Assert\Url(['message' => 'Please provide a valid URL (e.g. https://meet.google.com/...).']),
                ],
                'attr' => ['class' => 'form-control', 'placeholder' => 'https://meet.google.com/...'],
                'label_attr' => ['class' => 'form-label'],
            ])
            ->add('status', ChoiceType::class, [
                'choices' => [
                    'Pending' => 'PENDING',
                    'Accepted' => 'ACCEPTED',
                    'Rejected' => 'REJECTED',
                    'Completed' => 'COMPLETED',
                    'Cancelled' => 'CANCELLED',
                ],
                'placeholder' => 'Select status',
                'constraints' => [new Assert\NotBlank(['message' => 'Please select a status.'])],
                'attr' => ['class' => 'form-control form-select'],
                'label_attr' => ['class' => 'form-label'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Interview::class]);
    }
}
