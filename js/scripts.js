jQuery(function() {
    jQuery("#save").submit(function(e) 
	{
		e.preventDefault(); // avoid to execute the actual submit of the form.

		var form = jQuery(this);
		var url = form.attr('action');
		
		document.getElementById('loading-div').style.display = "block";
		
		jQuery.ajax({
			type: "POST",
			url: url,
			data: form.serialize(), // serializes the form's elements.
			success: function(data)
			{
				const Toast = Swal.mixin({
					toast: true,
					position: 'bottom',
					showConfirmButton: false,
					timer: 1500,
					timerProgressBar: true
				})
				
				document.getElementById('loading-div').style.display = "none";
				
				Toast.fire({
					icon: 'success',
					title: 'Purge settings saved!',
					didClose: () => {
						location.reload();
					}
				})
			}
		});
	});
	
	jQuery("#btnpurge").click( function(e)
		{
			e.preventDefault(); // avoid to execute the actual submit of the form.
		
			var phpToCall = window.location.origin + "/wp-admin/admin.php?page=remove_unapproved_users";

			Swal.fire({
				title: 'Are you sure?',
				text: "You won't be able to revert this!",
				icon: 'warning',
				showCancelButton: true,
				confirmButtonColor: '#3085d6',
				cancelButtonColor: '#d33',
				confirmButtonText: 'Yes',
				}).then((result) => {
				if (result.isConfirmed) {
					document.getElementById('loading-div').style.display = "block";
					jQuery.ajax({ 
						url: phpToCall,
						data: {purge:true},
						type: 'post',
						success: function(output) {
							const Toast = Swal.mixin({
								toast: true,
								position: 'bottom',
								showConfirmButton: false,
								timer: 1500,
								timerProgressBar: true,
								didClose: () => {
									location.reload();
								}
							})
							
							document.getElementById('loading-div').style.display = "none";

							Toast.fire({
								title: 'Users purged!'
							})
						}
					});
				}
			});
		}
    );
});