jQuery(function() {	
	const Toast = Swal.mixin({
		toast: true,
		position: 'center',
		showConfirmButton: false,
		timer: 1500,
		timerProgressBar: true
	})

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
				Toast.fire({
					icon: 'success',
					title: 'Purge settings saved!',
					didOpen: () => {
						location.reload();
					}
				})
			}
		});
	});
	
	jQuery("#btnpurge").click( function(e)
		{
			e.preventDefault(); // avoid to execute the actual submit of the form.
		
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
						url: dmkrtzRuu.ajax,
						data: { action: 'dmkrtz_ruu_purge', nonce: dmkrtzRuu.nonce },
						type: 'post',
						success: function(output) {
							var r = output.data || {};
							Toast.fire({
								icon: 'success',
								title: r.purged + ' users purged' + (r.remaining ? ', ' + r.remaining + ' more follow automatically' : '') + '!',
								didOpen: () => {
									location.reload();
								}
							})
						},
						error: function(jqXHR, error, errorThrown) {
							console.log(jqXHR);
							
							Toast.fire({
								icon: 'error',
								title: 'Error: ' + jqXHR.responseText,
								didClose: () => {
									location.reload();
								}
							})
						}
					});
				}
			});
		}
    );
});